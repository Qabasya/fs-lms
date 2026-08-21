<?php

declare( strict_types=1 );

namespace Unit\Services\Profile;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\SubmissionDTO;
use Inc\DTO\Course\WorkDTO;
use Inc\Enums\Course\WorkType;
use Inc\Enums\Profile\NotificationType;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\NotificationRepository;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;
use Inc\Services\Course\EffectiveWorksResolver;
use Inc\Services\Group\SessionCalendarService;
use Inc\Services\Profile\NotificationCronService;
use Inc\Services\Profile\NotificationService;
use PHPUnit\Framework\TestCase;

class NotificationCronServiceTest extends TestCase {

	private const NOW = '2026-01-15 12:00:00';

	private GroupLessonRepository&\PHPUnit\Framework\MockObject\MockObject  $groupLessons;
	private SubmissionRepository&\PHPUnit\Framework\MockObject\MockObject   $submissions;
	private EffectiveWorksResolver&\PHPUnit\Framework\MockObject\MockObject $worksResolver;
	private NotificationRepository&\PHPUnit\Framework\MockObject\MockObject $notificationRepository;
	private NotificationService&\PHPUnit\Framework\MockObject\MockObject   $notifications;
	private SessionCalendarService&\PHPUnit\Framework\MockObject\MockObject $calendar;
	private NotificationCronService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->groupLessons          = $this->createMock( GroupLessonRepository::class );
		$this->submissions           = $this->createMock( SubmissionRepository::class );
		$this->worksResolver         = $this->createMock( EffectiveWorksResolver::class );
		$this->notificationRepository = $this->createMock( NotificationRepository::class );
		$this->notifications         = $this->createMock( NotificationService::class );
		$this->calendar              = $this->createMock( SessionCalendarService::class );

		$clock = $this->createMock( ClockInterface::class );
		$clock->method( 'now' )->willReturn( self::NOW );

		$this->service = new NotificationCronService(
			$this->groupLessons,
			$this->submissions,
			$this->worksResolver,
			$this->notificationRepository,
			$this->notifications,
			$clock,
			$this->calendar,
		);
	}

	/**
	 * Настраивает оба источника занятий разом (ОДИН раз за тест — второй вызов
	 * ->method() на том же методе в PHPUnit не переопределяет первый, поэтому
	 * тесты должны явно объявлять «тихую» сторону, а не полагаться на дефолт из setUp()).
	 *
	 * @param GroupLessonDTO[] $startingBetween
	 * @param GroupLessonDTO[] $withDeadlines
	 * @param GroupLessonDTO[] $recentlyOpened
	 */
	private function stubLessons( array $startingBetween = array(), array $withDeadlines = array(), array $recentlyOpened = array() ): void {
		$this->groupLessons->method( 'listStartingBetween' )->willReturn( $startingBetween );
		$this->groupLessons->method( 'listWithDeadlines' )->willReturn( $withDeadlines );
		$this->groupLessons->method( 'listRecentlyOpened' )->willReturn( $recentlyOpened );
	}

	private function lesson( array $overrides = array() ): GroupLessonDTO {
		$base = array(
			'id' => 100, 'group_id' => 5, 'lesson_id' => null, 'position' => 0,
			'kind' => 'group', 'status' => 'scheduled', 'visibility' => 'open',
			'scheduled_at' => '2026-01-15 12:20:00',
		);
		return GroupLessonDTO::fromArray( array_merge( $base, $overrides ) );
	}

	private function work( int $id = 50 ): WorkDTO {
		return new WorkDTO( id: $id, subjectKey: 'math', title: 'Домашняя работа', workType: WorkType::Homework, itemIds: array( 77 ), instructions: '', authorId: 1, status: 'publish' );
	}

	public function test_tick_always_purges_with_retention_windows(): void {
		$this->stubLessons();
		$this->notificationRepository->expects( self::once() )->method( 'purge' )->with( 30, 90 );

		$this->service->tick();
	}

	public function test_lesson_soon_queries_exact_30_minute_window(): void {
		$this->groupLessons->expects( self::once() )
			->method( 'listStartingBetween' )
			->with( self::NOW, '2026-01-15 12:30:00' )
			->willReturn( array() );
		$this->groupLessons->method( 'listWithDeadlines' )->willReturn( array() );
		$this->groupLessons->method( 'listRecentlyOpened' )->willReturn( array() );

		$this->service->tick();
	}

	public function test_lesson_soon_pushes_to_students_and_teacher_with_lesson_url(): void {
		$lesson = $this->lesson( array( 'lesson_id' => 7 ) );
		$this->stubLessons( startingBetween: array( $lesson ) );
		$this->notifications->method( 'lessonStudentUserIds' )->with( $lesson )->willReturn( array( 21, 22 ) );
		$this->notifications->method( 'lessonTeacherUserId' )->with( $lesson )->willReturn( 55 );
		$this->notifications->method( 'lessonTopic' )->willReturn( 'Тема' );
		$this->notifications->method( 'groupName' )->willReturn( 'Группа' );

		$this->notifications->expects( self::once() )
			->method( 'push' )
			->with(
				array( 21, 22, 55 ),
				NotificationType::LessonSoon,
				'lesson_soon:100',
				self::callback( static fn( $p ) => 'Тема' === $p['topic'] && 'Группа' === $p['group_name'] ),
				self::stringContains( 'gid=5' ),
				5,
				'group_lesson',
				100
			);

		$this->service->tick();
	}

	public function test_lesson_soon_falls_back_to_profile_url_without_lesson_content(): void {
		$lesson = $this->lesson( array( 'lesson_id' => null ) );
		$this->stubLessons( startingBetween: array( $lesson ) );
		$this->notifications->method( 'lessonStudentUserIds' )->willReturn( array( 21 ) );
		$this->notifications->method( 'lessonTeacherUserId' )->willReturn( null );

		$this->notifications->expects( self::once() )
			->method( 'push' )
			->with( array( 21 ), NotificationType::LessonSoon, self::anything(), self::anything(), self::stringContains( '/profile/' ), 5, 'group_lesson', 100 );

		$this->service->tick();
	}

	public function test_lesson_soon_skips_when_no_recipients(): void {
		$this->stubLessons( startingBetween: array( $this->lesson() ) );
		$this->notifications->method( 'lessonStudentUserIds' )->willReturn( array() );
		$this->notifications->method( 'lessonTeacherUserId' )->willReturn( null );

		$this->notifications->expects( self::never() )->method( 'push' );

		$this->service->tick();
	}

	/* ── Этап 5: «Открыт урок» (вне расписания) ───────────────────────────── */

	private function periodMeta( array $lessonDays ): array {
		return array( 'period' => null, 'holidays' => array(), 'lessonDays' => $lessonDays, 'lessonTimes' => array() );
	}

	public function test_lesson_opened_notifies_students_for_off_schedule_lesson(): void {
		$lesson = $this->lesson( array( 'id' => 200, 'scheduled_at' => '2026-01-15 09:00:00', 'visibility' => 'hidden' ) );
		$this->groupLessons->expects( self::once() )
			->method( 'listRecentlyOpened' )
			->with( '2026-01-14 12:00:00', self::NOW )
			->willReturn( array( $lesson ) );
		$this->groupLessons->method( 'listStartingBetween' )->willReturn( array() );
		$this->groupLessons->method( 'listWithDeadlines' )->willReturn( array() );
		$this->calendar->method( 'periodMeta' )->with( 5 )->willReturn( $this->periodMeta( array( '2026-01-10' ) ) ); // 15-е не в расписании
		$this->notifications->method( 'lessonStudentUserIds' )->with( $lesson )->willReturn( array( 31, 32 ) );
		$this->notifications->method( 'lessonTopic' )->willReturn( 'Тема вне расписания' );
		$this->notifications->method( 'groupName' )->willReturn( 'Группа' );

		$this->notifications->expects( self::once() )
			->method( 'push' )
			->with(
				array( 31, 32 ),
				NotificationType::LessonOpened,
				'opened:200',
				self::callback( static fn( $p ) => 'Тема вне расписания' === $p['topic'] ),
				self::anything(),
				5,
				'group_lesson',
				200
			);

		$this->service->tick();
	}

	/** Плановое занятие (день в lessonDays) — LessonSoon уже предупредил, LessonOpened не дублирует. */
	public function test_lesson_opened_skips_when_day_is_in_schedule(): void {
		$lesson = $this->lesson( array( 'id' => 200, 'scheduled_at' => '2026-01-15 09:00:00', 'visibility' => 'hidden' ) );
		$this->stubLessons( recentlyOpened: array( $lesson ) );
		$this->calendar->method( 'periodMeta' )->willReturn( $this->periodMeta( array( '2026-01-15' ) ) ); // день в расписании

		$this->notifications->expects( self::never() )->method( 'push' );

		$this->service->tick();
	}

	public function test_lesson_opened_skips_when_no_recipients(): void {
		$lesson = $this->lesson( array( 'id' => 200, 'scheduled_at' => '2026-01-15 09:00:00', 'visibility' => 'hidden' ) );
		$this->stubLessons( recentlyOpened: array( $lesson ) );
		$this->calendar->method( 'periodMeta' )->willReturn( $this->periodMeta( array() ) );
		$this->notifications->method( 'lessonStudentUserIds' )->willReturn( array() );

		$this->notifications->expects( self::never() )->method( 'push' );

		$this->service->tick();
	}

	public function test_lesson_opened_dedup_key_stable_across_repeated_ticks(): void {
		$lesson = $this->lesson( array( 'id' => 200, 'scheduled_at' => '2026-01-15 09:00:00', 'visibility' => 'hidden' ) );
		$this->stubLessons( recentlyOpened: array( $lesson ) );
		$this->calendar->method( 'periodMeta' )->willReturn( $this->periodMeta( array() ) );
		$this->notifications->method( 'lessonStudentUserIds' )->willReturn( array( 31 ) );

		$this->notifications->expects( self::exactly( 2 ) )
			->method( 'push' )
			->with( self::anything(), NotificationType::LessonOpened, 'opened:200', self::anything(), self::anything(), 5, 'group_lesson', 200 );

		$this->service->tick();
		$this->service->tick();
	}

	public function test_deadline_soon_notifies_only_students_without_submission(): void {
		$lesson = $this->lesson( array( 'homework_due_at' => '2026-01-15 14:00:00' ) ); // now+2h — внутри (now, now+24h]
		$this->stubLessons( withDeadlines: array( $lesson ) );
		$this->worksResolver->method( 'resolve' )->with( $lesson )->willReturn( array( $this->work( 50 ) ) );
		$this->notifications->method( 'lessonStudentPersonIds' )->with( $lesson )->willReturn( array( 10, 11 ) );

		$this->submissions->method( 'listByStudentAndGroupLesson' )->willReturnMap( array(
			array( 10, 100, array() ), // не сдал
			array( 11, 100, array( SubmissionDTO::fromArray( array(
				'id' => 1, 'student_person_id' => 11, 'group_lesson_id' => 100, 'work_id' => 50,
				'work_type' => 'homework', 'status' => 'submitted',
				'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
			) ) ) ), // сдал работу 50 — исключается
		) );
		$this->notifications->method( 'studentUserId' )->with( 10 )->willReturn( 77 );
		$this->notifications->method( 'lessonWorkUrl' )->willReturn( '/group/?gid=5&gl=100&step=x' );

		$this->notifications->expects( self::once() )
			->method( 'push' )
			->with(
				array( 77 ),
				NotificationType::DeadlineSoon,
				'dl_soon:100:50',
				self::callback( static fn( $p ) => 'Домашняя работа' === $p['topic'] ),
				'/group/?gid=5&gl=100&step=x',
				5,
				'group_lesson',
				100
			);
		$this->notifications->expects( self::never() )->method( 'guardianUserIds' );

		$this->service->tick();
	}

	public function test_deadline_missed_notifies_student_and_guardians(): void {
		$lesson = $this->lesson( array( 'homework_due_at' => '2026-01-15 10:00:00' ) ); // now-2h — внутри [now-24h, now)
		$this->stubLessons( withDeadlines: array( $lesson ) );
		$this->worksResolver->method( 'resolve' )->willReturn( array( $this->work( 50 ) ) );
		$this->notifications->method( 'lessonStudentPersonIds' )->willReturn( array( 10 ) );
		$this->submissions->method( 'listByStudentAndGroupLesson' )->willReturn( array() );
		$this->notifications->method( 'studentUserId' )->with( 10 )->willReturn( 77 );
		$this->notifications->method( 'guardianUserIds' )->with( 10 )->willReturn( array( 88 ) );

		$this->notifications->expects( self::once() )
			->method( 'push' )
			->with( array( 77, 88 ), NotificationType::DeadlineMissed, 'dl_miss:100:50', self::anything(), self::anything(), 5, 'group_lesson', 100 );

		$this->service->tick();
	}

	public function test_deadline_outside_both_windows_is_not_notified(): void {
		$tooFar = $this->lesson( array( 'id' => 101, 'homework_due_at' => '2026-01-16 13:00:00' ) ); // now+25h
		$tooOld = $this->lesson( array( 'id' => 102, 'homework_due_at' => '2026-01-14 11:00:00' ) ); // now-25h
		$this->stubLessons( withDeadlines: array( $tooFar, $tooOld ) );
		$this->worksResolver->method( 'resolve' )->willReturn( array( $this->work( 50 ) ) );
		$this->notifications->method( 'lessonStudentPersonIds' )->willReturn( array( 10 ) );
		$this->submissions->method( 'listByStudentAndGroupLesson' )->willReturn( array() );

		$this->notifications->expects( self::never() )->method( 'push' );

		$this->service->tick();
	}

	public function test_deadline_skipped_when_no_students(): void {
		$lesson = $this->lesson( array( 'homework_due_at' => '2026-01-15 14:00:00' ) );
		$this->stubLessons( withDeadlines: array( $lesson ) );
		$this->worksResolver->expects( self::never() )->method( 'resolve' );
		$this->notifications->method( 'lessonStudentPersonIds' )->willReturn( array() );

		$this->notifications->expects( self::never() )->method( 'push' );

		$this->service->tick();
	}

	public function test_dedupe_keys_are_stable_across_repeated_ticks(): void {
		$lesson = $this->lesson( array( 'lesson_id' => 7 ) );
		$this->stubLessons( startingBetween: array( $lesson ) );
		$this->notifications->method( 'lessonStudentUserIds' )->willReturn( array( 21 ) );
		$this->notifications->method( 'lessonTeacherUserId' )->willReturn( null );

		$this->notifications->expects( self::exactly( 2 ) )
			->method( 'push' )
			->with( self::anything(), NotificationType::LessonSoon, 'lesson_soon:100', self::anything(), self::anything(), 5, 'group_lesson', 100 );

		$this->service->tick();
		$this->service->tick();
	}
}
