<?php

declare( strict_types=1 );

namespace Unit\Services\Profile;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\Enums\Profile\NotificationType;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\SubstitutionRepository;
use Inc\Services\Person\PresenceService;
use Inc\Services\Profile\AdminAlertCronService;
use Inc\Services\Profile\AdminAlertService;
use Inc\Services\Profile\NotificationService;
use PHPUnit\Framework\TestCase;

class AdminAlertCronServiceTest extends TestCase {

	private const NOW = '2026-05-20 12:00:00';

	private AdminAlertService&\PHPUnit\Framework\MockObject\MockObject      $alerts;
	private NotificationService&\PHPUnit\Framework\MockObject\MockObject    $notifications;
	private GroupLessonRepository&\PHPUnit\Framework\MockObject\MockObject  $groupLessons;
	private SubstitutionRepository&\PHPUnit\Framework\MockObject\MockObject $substitutions;
	private PresenceService&\PHPUnit\Framework\MockObject\MockObject        $presence;
	private AdminAlertCronService $service;

	/** @var array<int, array{0: NotificationType, 1: string, 2: int[]}> */
	private array $pushed = array();

	protected function setUp(): void {
		parent::setUp();

		$this->alerts        = $this->createMock( AdminAlertService::class );
		$this->notifications = $this->createMock( NotificationService::class );
		$this->groupLessons  = $this->createMock( GroupLessonRepository::class );
		$this->substitutions = $this->createMock( SubstitutionRepository::class );
		$this->presence      = $this->createMock( PresenceService::class );

		$clock = $this->createMock( ClockInterface::class );
		$clock->method( 'now' )->willReturn( self::NOW );

		$this->notifications->method( 'push' )->willReturnCallback(
			function ( array $users, NotificationType $type, string $key ): void {
				$this->pushed[] = array( $type, $key, $users );
			}
		);

		$this->service = new AdminAlertCronService(
			$this->alerts,
			$this->notifications,
			$this->groupLessons,
			$this->substitutions,
			$this->presence,
			$clock,
		);
	}

	private function lesson( array $overrides = array() ): GroupLessonDTO {
		return GroupLessonDTO::fromArray( array_merge( array(
			'id' => 100, 'group_id' => 5, 'lesson_id' => null, 'position' => 0,
			'kind' => 'group', 'status' => 'scheduled', 'visibility' => 'open',
			'scheduled_at' => '2026-05-20 11:30:00',
		), $overrides ) );
	}

	/** Все источники молчат, кроме явно настроенных в тесте. */
	private function quiet( array $except = array() ): void {
		foreach ( array( 'overdueJournals', 'homeworkStreaks', 'overdueReviews' ) as $method ) {
			if ( ! in_array( $method, $except, true ) ) {
				$this->alerts->method( $method )->willReturn( array() );
			}
		}
		if ( ! in_array( 'listStartingBetween', $except, true ) ) {
			$this->groupLessons->method( 'listStartingBetween' )->willReturn( array() );
		}
	}

	private function teacherOfLesson(): void {
		$this->notifications->method( 'adminUserIds' )->willReturn( array( 2 ) );
		$this->notifications->method( 'lessonStudentPersonIds' )->willReturn( array( 10 ) );
		$this->notifications->method( 'lessonTeacherUserId' )->willReturn( 55 );
	}

	public function test_nothing_is_computed_without_admins(): void {
		$this->notifications->method( 'adminUserIds' )->willReturn( array() );
		$this->alerts->expects( self::never() )->method( 'overdueJournals' );

		$this->service->tick();

		self::assertSame( array(), $this->pushed );
	}

	public function test_journal_overdue_uses_day_old_window(): void {
		$this->notifications->method( 'adminUserIds' )->willReturn( array( 2 ) );
		$this->quiet( array( 'overdueJournals' ) );
		$this->alerts->expects( self::once() )->method( 'overdueJournals' )
			->with( '2026-05-18 12:00:00', '2026-05-19 12:00:00' )
			->willReturn( array( array(
				'lesson' => $this->lesson(), 'teacher_user_id' => 55, 'teacher_name' => 'Петров Пётр', 'topic' => 'Циклы', 'group_name' => 'КЕГЭ-1',
			) ) );

		$this->service->tick();

		self::assertSame( array( array( NotificationType::JournalOverdue, 'journal_admin:100', array( 2 ) ) ), $this->pushed );
	}

	/** Серия, последний срок которой прошёл давно, повторно не всплывает. */
	public function test_only_fresh_homework_streaks_are_sent(): void {
		$this->notifications->method( 'adminUserIds' )->willReturn( array( 2 ) );
		$this->quiet( array( 'homeworkStreaks' ) );
		$streak = array( 'person_id' => 10, 'group_id' => 5, 'student_name' => 'Иванов Иван', 'group_name' => 'КЕГЭ-1', 'count' => 3 );
		$this->alerts->method( 'homeworkStreaks' )->willReturn( array(
			$streak + array( 'first_key' => '101:51', 'latest_due' => '2026-05-20 09:00:00' ),
			array( 'person_id' => 11 ) + $streak + array( 'first_key' => '101:51', 'latest_due' => '2026-05-10 09:00:00' ),
		) );

		$this->service->tick();

		self::assertSame( array( array( NotificationType::HomeworkStreak, 'hw_streak:10:5:101:51', array( 2 ) ) ), $this->pushed );
	}

	public function test_review_overdue_is_sent_per_submission(): void {
		$this->notifications->method( 'adminUserIds' )->willReturn( array( 2 ) );
		$this->quiet( array( 'overdueReviews' ) );
		$this->alerts->method( 'overdueReviews' )->willReturn( array( array(
			'submission_id' => 7, 'group_id' => 5, 'teacher_name' => 'Петров Пётр', 'student_name' => 'Иванов Иван',
			'topic' => 'КР', 'group_name' => 'КЕГЭ-1', 'notified_at' => '2026-05-18 10:00:00',
		) ) );

		$this->service->tick();

		self::assertSame( array( array( NotificationType::ReviewOverdue, 'review_admin:7', array( 2 ) ) ), $this->pushed );
	}

	public function test_teacher_not_seen_since_before_lesson_is_absent(): void {
		$this->teacherOfLesson();
		$this->quiet( array( 'listStartingBetween' ) );
		$this->groupLessons->expects( self::once() )->method( 'listStartingBetween' )
			->with( '2026-05-19 12:00:00', '2026-05-20 11:45:00' )
			->willReturn( array( $this->lesson() ) );
		$this->substitutions->method( 'findActiveForGroup' )->willReturn( null );
		$this->presence->method( 'lastSeenAt' )->with( 55 )->willReturn( '2026-05-19 18:00:00' );

		$this->service->tick();

		self::assertSame( array( array( NotificationType::TeacherAbsent, 'teacher_absent:100', array( 2 ) ) ), $this->pushed );
	}

	public function test_teacher_seen_shortly_before_lesson_is_present(): void {
		$this->teacherOfLesson();
		$this->quiet( array( 'listStartingBetween' ) );
		$this->groupLessons->method( 'listStartingBetween' )->willReturn( array( $this->lesson() ) );
		$this->substitutions->method( 'findActiveForGroup' )->willReturn( null );
		$this->presence->method( 'lastSeenAt' )->willReturn( '2026-05-20 11:20:00' );

		$this->service->tick();

		self::assertSame( array(), $this->pushed );
	}

	public function test_lesson_with_substitution_is_not_checked(): void {
		$this->teacherOfLesson();
		$this->quiet( array( 'listStartingBetween' ) );
		$this->groupLessons->method( 'listStartingBetween' )->willReturn( array( $this->lesson() ) );
		$this->substitutions->method( 'findActiveForGroup' )->with( 5, '2026-05-20' )
			->willReturn( $this->createStub( \Inc\DTO\Course\SubstitutionDTO::class ) );
		$this->presence->expects( self::never() )->method( 'lastSeenAt' );

		$this->service->tick();

		self::assertSame( array(), $this->pushed );
	}

	/** Нет ни одной отметки присутствия — нет данных, а не отсутствие. */
	public function test_teacher_without_presence_data_is_not_reported(): void {
		$this->teacherOfLesson();
		$this->quiet( array( 'listStartingBetween' ) );
		$this->groupLessons->method( 'listStartingBetween' )->willReturn( array( $this->lesson() ) );
		$this->substitutions->method( 'findActiveForGroup' )->willReturn( null );
		$this->presence->method( 'lastSeenAt' )->willReturn( null );

		$this->service->tick();

		self::assertSame( array(), $this->pushed );
	}
}
