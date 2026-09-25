<?php

declare( strict_types=1 );

namespace Unit\Services\Profile;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\SubmissionDTO;
use Inc\DTO\Course\WorkDTO;
use Inc\DTO\Enrollment\StudentRecordDTO;
use Inc\DTO\Profile\NotificationDTO;
use Inc\Enums\Course\WorkType;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\NotificationRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;
use Inc\Services\Course\AttendanceService;
use Inc\Services\Course\HomeworkDeadlineService;
use Inc\Services\Profile\AdminAlertService;
use Inc\Services\Profile\NotificationService;
use PHPUnit\Framework\TestCase;

class AdminAlertServiceTest extends TestCase {

	private GroupsRepository&\PHPUnit\Framework\MockObject\MockObject        $groups;
	private GroupLessonRepository&\PHPUnit\Framework\MockObject\MockObject   $groupLessons;
	private StudentRecordRepository&\PHPUnit\Framework\MockObject\MockObject $records;
	private SubmissionRepository&\PHPUnit\Framework\MockObject\MockObject    $submissions;
	private NotificationRepository&\PHPUnit\Framework\MockObject\MockObject  $notificationRepository;
	private AttendanceService&\PHPUnit\Framework\MockObject\MockObject       $attendance;
	private HomeworkDeadlineService&\PHPUnit\Framework\MockObject\MockObject $homework;
	private NotificationService&\PHPUnit\Framework\MockObject\MockObject     $notifications;
	private AdminAlertService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->groups                 = $this->createMock( GroupsRepository::class );
		$this->groupLessons           = $this->createMock( GroupLessonRepository::class );
		$this->records                = $this->createMock( StudentRecordRepository::class );
		$this->submissions            = $this->createMock( SubmissionRepository::class );
		$this->notificationRepository = $this->createMock( NotificationRepository::class );
		$this->attendance             = $this->createMock( AttendanceService::class );
		$this->homework               = $this->createMock( HomeworkDeadlineService::class );
		$this->notifications          = $this->createMock( NotificationService::class );

		$clock = $this->createMock( ClockInterface::class );
		$clock->method( 'now' )->willReturn( '2026-05-20 12:00:00' );

		$this->service = new AdminAlertService(
			$this->groups,
			$this->groupLessons,
			$this->records,
			$this->submissions,
			$this->notificationRepository,
			$this->attendance,
			$this->homework,
			$this->notifications,
			$clock,
		);
	}

	private function lesson( int $id, array $overrides = array() ): GroupLessonDTO {
		return GroupLessonDTO::fromArray( array_merge( array(
			'id' => $id, 'group_id' => 5, 'lesson_id' => null, 'position' => 0,
			'kind' => 'group', 'status' => 'scheduled', 'visibility' => 'open',
			'scheduled_at' => '2026-05-01 10:00:00',
		), $overrides ) );
	}

	private function work( int $id ): WorkDTO {
		return new WorkDTO( id: $id, subjectKey: 'inf', title: "ДЗ {$id}", workType: WorkType::Homework, itemIds: array(), instructions: '', authorId: 1, status: 'publish' );
	}

	private function record( int $personId, string $enrolledAt = '2026-01-01 00:00:00' ): StudentRecordDTO {
		return StudentRecordDTO::fromArray( array(
			'id' => $personId, 'student_person_id' => $personId, 'parent_person_id' => 0, 'group_id' => 5,
			'snapshot_last_name' => 'Иванов', 'snapshot_first_name' => 'Иван', 'status' => 'active',
			'enrolled_at' => $enrolledAt, 'created_at' => $enrolledAt, 'updated_at' => $enrolledAt,
		) );
	}

	private function group(): object {
		return (object) array( 'id' => 5, 'name' => 'КЕГЭ-1', 'access_mode' => 'scheduled', 'deleted_at' => null );
	}

	/**
	 * Четыре ДЗ со сроком в прошлом.
	 *
	 * @return array<int, array{row: GroupLessonDTO, work: WorkDTO, due_at: string}>
	 */
	private function dueHomework(): array {
		$out = array();
		foreach ( array( 1, 2, 3, 4 ) as $i ) {
			$out[] = array( 'row' => $this->lesson( 100 + $i ), 'work' => $this->work( 50 + $i ), 'due_at' => "2026-05-0{$i} 10:00:00" );
		}
		return $out;
	}

	private function submitted( int $personId, int $groupLessonId, int $workId ): SubmissionDTO {
		return SubmissionDTO::fromArray( array(
			'id' => 1, 'student_person_id' => $personId, 'group_lesson_id' => $groupLessonId, 'work_id' => $workId, 'status' => 'graded',
		) );
	}

	public function test_homework_streak_from_three_unsubmitted_is_reported(): void {
		$this->groups->method( 'findAll' )->willReturn( array( $this->group() ) );
		$this->records->method( 'findActiveByGroupId' )->willReturn( array( $this->record( 10 ), $this->record( 11 ) ) );
		$this->groupLessons->method( 'listByGroup' )->willReturn( array() );
		$this->homework->method( 'dueHomework' )->willReturn( $this->dueHomework() );
		// Ученик 11 сдал одну работу из середины — серия прервана.
		$this->submissions->method( 'listForGradebookByGroup' )->willReturn( array( $this->submitted( 11, 103, 53 ) ) );

		$out = $this->service->homeworkStreaks();

		self::assertCount( 1, $out );
		self::assertSame( 10, $out[0]['person_id'] );
		self::assertSame( 4, $out[0]['count'] );
		self::assertSame( '101:51', $out[0]['first_key'] );
		self::assertSame( '2026-05-04 10:00:00', $out[0]['latest_due'] );
	}

	/** Ровно три несданных подряд — порог; две — ещё нет. */
	public function test_homework_streak_threshold_is_three(): void {
		$this->groups->method( 'findAll' )->willReturn( array( $this->group() ) );
		$this->records->method( 'findActiveByGroupId' )->willReturn( array( $this->record( 10 ), $this->record( 11 ) ) );
		$this->groupLessons->method( 'listByGroup' )->willReturn( array() );
		$this->homework->method( 'dueHomework' )->willReturn( $this->dueHomework() );
		$this->submissions->method( 'listForGradebookByGroup' )->willReturn( array(
			$this->submitted( 10, 101, 51 ), // у 10 не сданы 2-я, 3-я, 4-я — серия из трёх
			$this->submitted( 11, 102, 52 ), // у 11 не сданы 3-я и 4-я — две
		) );

		$out = $this->service->homeworkStreaks();

		self::assertCount( 1, $out );
		self::assertSame( array( 10, 3, '102:52' ), array( $out[0]['person_id'], $out[0]['count'], $out[0]['first_key'] ) );
	}

	/** ДЗ со сроком до зачисления в серию не идут — новичок не «не сдаёт работы». */
	public function test_homework_before_enrollment_is_not_counted(): void {
		$this->groups->method( 'findAll' )->willReturn( array( $this->group() ) );
		$this->records->method( 'findActiveByGroupId' )->willReturn( array( $this->record( 10, '2026-05-03 00:00:00' ) ) );
		$this->groupLessons->method( 'listByGroup' )->willReturn( array() );
		$this->homework->method( 'dueHomework' )->willReturn( $this->dueHomework() );
		$this->submissions->method( 'listForGradebookByGroup' )->willReturn( array() );

		self::assertSame( array(), $this->service->homeworkStreaks() );
	}

	public function test_open_and_deleted_groups_are_skipped(): void {
		$this->groups->method( 'findAll' )->willReturn( array(
			(object) array( 'id' => 6, 'name' => 'Открытая', 'access_mode' => 'open', 'deleted_at' => null ),
			(object) array( 'id' => 7, 'name' => 'Удалённая', 'access_mode' => 'scheduled', 'deleted_at' => '2026-05-01 00:00:00' ),
		) );
		$this->attendance->expects( self::never() )->method( 'absenceStreaks' );

		self::assertSame( array(), $this->service->absenceStreaks() );
	}

	public function test_absence_streak_from_two_lessons_is_reported(): void {
		$this->groups->method( 'findAll' )->willReturn( array( $this->group() ) );
		$this->records->method( 'findActiveByGroupId' )->willReturn( array( $this->record( 10 ), $this->record( 11 ) ) );
		$this->attendance->method( 'absenceStreaks' )->willReturn( array( 10 => array( 102, 101 ), 11 => array( 102 ) ) );

		$out = $this->service->absenceStreaks();

		self::assertCount( 1, $out );
		self::assertSame( array( 10, 2, 101 ), array( $out[0]['person_id'], $out[0]['count'], $out[0]['first_lesson_id'] ) );
	}

	/** Непроверенная работа — в сигналах; проверенная и удалённая — нет. */
	public function test_overdue_reviews_keep_only_unchecked_submissions(): void {
		$notification = static fn( int $subId ): NotificationDTO => NotificationDTO::fromArray( array(
			'id' => $subId, 'recipient_user_id' => 55, 'type' => 'review_needed', 'group_id' => 5,
			'entity_type' => 'submission', 'entity_id' => $subId,
			'payload' => wp_json_encode( array( 'student_name' => 'Иванов Иван', 'topic' => 'КР', 'group_name' => 'КЕГЭ-1' ) ),
			'url' => '', 'created_at' => '2026-05-17 10:00:00', 'seen_at' => null, 'read_at' => null,
		) );
		$this->notificationRepository->method( 'listByTypeCreatedBetween' )
			->willReturn( array( $notification( 1 ), $notification( 2 ), $notification( 3 ) ) );
		$this->submissions->method( 'find' )->willReturnCallback( fn( int $id ) => match ( $id ) {
			1 => SubmissionDTO::fromArray( array( 'id' => 1, 'student_person_id' => 10, 'group_lesson_id' => 101, 'work_id' => 51, 'status' => 'pending_review' ) ),
			2 => SubmissionDTO::fromArray( array( 'id' => 2, 'student_person_id' => 10, 'group_lesson_id' => 101, 'work_id' => 52, 'status' => 'graded' ) ),
			default => null,
		} );
		$this->notifications->method( 'userDisplayName' )->with( 55 )->willReturn( 'Петров Пётр' );

		$out = $this->service->overdueReviews( '2026-05-01 00:00:00', '2026-05-18 12:00:00' );

		self::assertCount( 1, $out );
		self::assertSame( 1, $out[0]['submission_id'] );
		self::assertSame( 'Петров Пётр', $out[0]['teacher_name'] );
		self::assertSame( 'Иванов Иван', $out[0]['student_name'] );
	}

	public function test_overdue_journals_skip_filled_and_open_groups(): void {
		$this->groupLessons->method( 'listGroupEndedBetween' )->willReturn( array(
			$this->lesson( 101 ),
			$this->lesson( 102, array( 'has_attendance' => 1 ) ),
			$this->lesson( 103, array( 'group_id' => 6 ) ),
		) );
		$this->notifications->method( 'isOpenGroup' )->willReturnCallback( static fn( int $gid ): bool => 6 === $gid );
		$this->notifications->method( 'lessonStudentPersonIds' )->willReturn( array( 10 ) );
		$this->notifications->method( 'lessonTeacherUserId' )->willReturn( 55 );
		$this->notifications->method( 'userDisplayName' )->willReturn( 'Петров Пётр' );

		$out = $this->service->overdueJournals( '2026-05-18 12:00:00', '2026-05-19 12:00:00' );

		self::assertCount( 1, $out );
		self::assertSame( 101, $out[0]['lesson']->id );
		self::assertSame( 'Петров Пётр', $out[0]['teacher_name'] );
	}
}
