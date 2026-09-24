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
use Inc\Services\Profile\NotificationCronService;
use Inc\Services\Profile\NotificationService;
use Inc\Services\Course\LessonVisibilityService;
use PHPUnit\Framework\TestCase;

class NotificationCronServiceTest extends TestCase {

	private const NOW = '2026-01-15 12:00:00';

	private GroupLessonRepository&\PHPUnit\Framework\MockObject\MockObject  $groupLessons;
	private SubmissionRepository&\PHPUnit\Framework\MockObject\MockObject   $submissions;
	private EffectiveWorksResolver&\PHPUnit\Framework\MockObject\MockObject $worksResolver;
	private NotificationRepository&\PHPUnit\Framework\MockObject\MockObject $notificationRepository;
	private NotificationService&\PHPUnit\Framework\MockObject\MockObject   $notifications;
	private \Inc\Repositories\WPDBRepositories\AttendanceRepository&\PHPUnit\Framework\MockObject\MockObject $attendance;
	private \Inc\Repositories\WPDBRepositories\LessonProgressRepository&\PHPUnit\Framework\MockObject\MockObject $progress;
	private LessonVisibilityService&\PHPUnit\Framework\MockObject\MockObject $visibility;
	private NotificationCronService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->groupLessons          = $this->createMock( GroupLessonRepository::class );
		$this->submissions           = $this->createMock( SubmissionRepository::class );
		$this->worksResolver         = $this->createMock( EffectiveWorksResolver::class );
		$this->notificationRepository = $this->createMock( NotificationRepository::class );
		$this->notifications         = $this->createMock( NotificationService::class );
		$this->attendance            = $this->createMock( \Inc\Repositories\WPDBRepositories\AttendanceRepository::class );
		$this->progress              = $this->createMock( \Inc\Repositories\WPDBRepositories\LessonProgressRepository::class );
		$this->visibility            = $this->createMock( LessonVisibilityService::class );
		$this->visibility->method( 'effectiveVisibility' )->willReturnCallback(
			static fn( $row ) => 999 === $row->lessonId ? 'hidden' : 'open'
		);

		$clock = $this->createMock( ClockInterface::class );
		$clock->method( 'now' )->willReturn( self::NOW );

		$this->service = new NotificationCronService(
			$this->groupLessons,
			$this->submissions,
			$this->worksResolver,
			$this->notificationRepository,
			$this->notifications,
			$clock,
			$this->visibility,
			$this->attendance,
			$this->progress,
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
		// Второй вызов — окно «за 24 часа до следующего занятия» (ДЗ к следующему уроку).
		$windows = array();
		$this->groupLessons->expects( self::exactly( 2 ) )
			->method( 'listStartingBetween' )
			->willReturnCallback( function ( string $from, string $to ) use ( &$windows ): array {
				$windows[] = array( $from, $to );
				return array();
			} );
		$this->groupLessons->method( 'listWithDeadlines' )->willReturn( array() );
		$this->groupLessons->method( 'listRecentlyOpened' )->willReturn( array() );

		$this->service->tick();

		self::assertSame( array( self::NOW, '2026-01-15 12:30:00' ), $windows[0] );
		self::assertSame( array( self::NOW, '2026-01-16 12:00:00' ), $windows[1] );
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

	public function test_lesson_opened_notifies_students(): void {
		$lesson = $this->lesson( array( 'id' => 200, 'scheduled_at' => '2026-01-15 09:00:00', 'visibility' => 'hidden' ) );
		$this->groupLessons->expects( self::once() )
			->method( 'listRecentlyOpened' )
			->with( '2026-01-14 12:00:00', self::NOW )
			->willReturn( array( $lesson ) );
		$this->groupLessons->method( 'listStartingBetween' )->willReturn( array() );
		$this->groupLessons->method( 'listWithDeadlines' )->willReturn( array() );
		$this->notifications->method( 'lessonStudentUserIds' )->with( $lesson )->willReturn( array( 31, 32 ) );
		$this->notifications->method( 'lessonTopic' )->willReturn( 'Тема' );
		$this->notifications->method( 'groupName' )->willReturn( 'Группа' );

		$this->notifications->expects( self::once() )
			->method( 'push' )
			->with(
				array( 31, 32 ),
				NotificationType::LessonOpened,
				'opened:200',
				self::callback( static fn( $p ) => 'Тема' === $p['topic'] ),
				self::anything(),
				5,
				'group_lesson',
				200
			);

		$this->service->tick();
	}

	/** Урок курса — черновик: по дате не открылся, уведомлять не о чем. */
	public function test_lesson_opened_skips_draft_lesson(): void {
		$lesson = $this->lesson( array( 'id' => 200, 'lesson_id' => 999, 'scheduled_at' => '2026-01-15 09:00:00', 'visibility' => 'hidden' ) );
		$this->stubLessons( recentlyOpened: array( $lesson ) );
		$this->notifications->method( 'lessonStudentUserIds' )->willReturn( array( 31 ) );

		$this->notifications->expects( self::never() )->method( 'push' );

		$this->service->tick();
	}

	public function test_lesson_opened_skips_when_no_recipients(): void {
		$lesson = $this->lesson( array( 'id' => 200, 'scheduled_at' => '2026-01-15 09:00:00', 'visibility' => 'hidden' ) );
		$this->stubLessons( recentlyOpened: array( $lesson ) );
		$this->notifications->method( 'lessonStudentUserIds' )->willReturn( array() );

		$this->notifications->expects( self::never() )->method( 'push' );

		$this->service->tick();
	}

	public function test_lesson_opened_dedup_key_stable_across_repeated_ticks(): void {
		$lesson = $this->lesson( array( 'id' => 200, 'scheduled_at' => '2026-01-15 09:00:00', 'visibility' => 'hidden' ) );
		$this->stubLessons( recentlyOpened: array( $lesson ) );
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

	// --- Домашняя работа сдаётся к следующему занятию ---

	private function submitted( int $personId, int $groupLessonId, int $workId ): SubmissionDTO {
		return SubmissionDTO::fromArray( array(
			'id' => 1, 'student_person_id' => $personId, 'group_lesson_id' => $groupLessonId, 'work_id' => $workId,
			'work_type' => 'homework', 'status' => 'submitted',
			'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
		) );
	}

	/** За 24 часа до следующего занятия — «скоро сдача» тем, кто ДЗ прошлого занятия не сдал. */
	public function test_homework_soon_before_next_lesson(): void {
		$previous = $this->lesson( array( 'id' => 100, 'scheduled_at' => '2026-01-08 16:00:00' ) );
		$next     = $this->lesson( array( 'id' => 101, 'scheduled_at' => '2026-01-16 10:00:00' ) );
		$this->stubLessons( startingBetween: array( $next ) );
		$this->groupLessons->method( 'listByGroup' )->willReturn( array( $previous, $next ) );
		$this->worksResolver->method( 'resolve' )->willReturn( array( $this->work( 50 ) ) );
		$this->notifications->method( 'lessonStudentPersonIds' )->willReturn( array( 10, 11 ) );
		$this->submissions->method( 'listByStudentAndGroupLesson' )->willReturnMap( array(
			array( 10, 100, array() ),
			array( 11, 100, array( $this->submitted( 11, 100, 50 ) ) ),
		) );
		$this->notifications->method( 'studentUserId' )->with( 10 )->willReturn( 77 );
		$this->notifications->method( 'lessonStudentUserIds' )->willReturn( array() );

		$this->notifications->expects( self::once() )
			->method( 'push' )
			->with( array( 77 ), NotificationType::DeadlineSoon, 'dl_soon:100:50', self::anything(), self::anything(), 5, 'group_lesson', 100 );

		$this->service->tick();
	}

	/** У работы свой дедлайн — «к следующему занятию» её не касается. */
	public function test_homework_with_explicit_deadline_is_not_due_at_next_lesson(): void {
		$previous = $this->lesson( array( 'id' => 100, 'scheduled_at' => '2026-01-08 16:00:00', 'work_deadlines' => '{"50":"2026-02-01 10:00:00"}' ) );
		$next     = $this->lesson( array( 'id' => 101, 'scheduled_at' => '2026-01-16 10:00:00' ) );
		$this->stubLessons( startingBetween: array( $next ) );
		$this->groupLessons->method( 'listByGroup' )->willReturn( array( $previous, $next ) );
		$this->worksResolver->method( 'resolve' )->willReturn( array( $this->work( 50 ) ) );
		$this->notifications->method( 'lessonStudentPersonIds' )->willReturn( array( 10 ) );
		$this->notifications->method( 'lessonStudentUserIds' )->willReturn( array() );

		$this->notifications->expects( self::never() )->method( 'push' );

		$this->service->tick();
	}

	/**
	 * Началось следующее занятие: ДЗ прошлого не сдано — «пропущена сдача» ученику
	 * и родителю; отсутствовал, урок не открыл, ДЗ не сдал — «пропущено занятие».
	 */
	public function test_next_lesson_began_reports_missed_homework_and_missed_lesson(): void {
		$previous = $this->lesson( array( 'id' => 100, 'scheduled_at' => '2026-01-08 16:00:00' ) );
		$next     = $this->lesson( array( 'id' => 101, 'scheduled_at' => '2026-01-15 11:00:00' ) );
		$this->stubLessons();
		$this->groupLessons->method( 'listGroupBeganBetween' )->willReturn( array( $next ) );
		$this->groupLessons->method( 'listByGroup' )->willReturn( array( $previous, $next ) );
		$this->worksResolver->method( 'resolve' )->willReturn( array( $this->work( 50 ) ) );
		$this->notifications->method( 'lessonStudentPersonIds' )->willReturn( array( 10 ) );
		$this->submissions->method( 'listByStudentAndGroupLesson' )->willReturn( array() );
		$this->attendance->method( 'listByGroupLesson' )->willReturn( array( \Inc\DTO\Course\AttendanceDTO::fromArray( array(
			'id' => 1, 'group_lesson_id' => 100, 'student_person_id' => 10, 'is_present' => 0, 'marked_at' => '2026-01-08 17:00:00',
		) ) ) );
		$this->progress->method( 'listByGroupLesson' )->willReturn( array() );
		$this->notifications->method( 'studentUserId' )->willReturn( 77 );
		$this->notifications->method( 'guardianUserIds' )->willReturn( array( 88 ) );

		$pushed = array();
		$this->notifications->method( 'push' )->willReturnCallback(
			function ( array $users, NotificationType $type, string $key ) use ( &$pushed ): void {
				$pushed[] = array( $type, $key, $users );
			}
		);

		$this->service->tick();

		self::assertContains( array( NotificationType::DeadlineMissed, 'dl_miss:100:50', array( 77, 88 ) ), $pushed );
		self::assertContains( array( NotificationType::AttendanceMissed, 'att:100:10', array( 77 ) ), $pushed );
		self::assertContains( array( NotificationType::AttendanceMissed, 'att:100:10', array( 88 ) ), $pushed );
	}

	/** Отсутствовал, но урок потом открыл — нагоняет сам, «пропущено занятие» не нужно. */
	public function test_absent_student_who_viewed_lesson_is_not_reported(): void {
		$previous = $this->lesson( array( 'id' => 100, 'scheduled_at' => '2026-01-08 16:00:00' ) );
		$next     = $this->lesson( array( 'id' => 101, 'scheduled_at' => '2026-01-15 11:00:00' ) );
		$this->stubLessons();
		$this->groupLessons->method( 'listGroupBeganBetween' )->willReturn( array( $next ) );
		$this->groupLessons->method( 'listByGroup' )->willReturn( array( $previous, $next ) );
		$this->worksResolver->method( 'resolve' )->willReturn( array() );
		$this->attendance->method( 'listByGroupLesson' )->willReturn( array( \Inc\DTO\Course\AttendanceDTO::fromArray( array(
			'id' => 1, 'group_lesson_id' => 100, 'student_person_id' => 10, 'is_present' => 0, 'marked_at' => '2026-01-08 17:00:00',
		) ) ) );
		$this->progress->method( 'listByGroupLesson' )->willReturn( array( \Inc\DTO\Course\LessonProgressDTO::fromArray( array(
			'id' => 1, 'student_person_id' => 10, 'group_lesson_id' => 100, 'lesson_id' => 5, 'step_key' => 's1', 'status' => 'viewed',
		) ) ) );

		$this->notifications->expects( self::never() )->method( 'push' );

		$this->service->tick();
	}

	/** Через час после конца занятия посещаемость не отмечена — преподавателю. */
	public function test_journal_not_filled_notifies_teacher(): void {
		$lesson = $this->lesson( array( 'id' => 100, 'scheduled_at' => '2026-01-15 09:00:00', 'ends_at' => '2026-01-15 10:30:00' ) );
		$this->stubLessons();
		$this->groupLessons->expects( self::once() )
			->method( 'listGroupEndedBetween' )
			->with( '2026-01-14 11:00:00', '2026-01-15 11:00:00' )
			->willReturn( array( $lesson ) );
		$this->notifications->method( 'lessonStudentPersonIds' )->willReturn( array( 10 ) );
		$this->notifications->method( 'lessonTeacherUserId' )->willReturn( 55 );

		$this->notifications->expects( self::once() )
			->method( 'push' )
			->with( array( 55 ), NotificationType::JournalNotFilled, 'journal:100', self::anything(), self::anything(), 5, 'group_lesson', 100 );

		$this->service->tick();
	}

	public function test_journal_with_attendance_is_not_reported(): void {
		$lesson = $this->lesson( array( 'id' => 100, 'scheduled_at' => '2026-01-15 09:00:00', 'has_attendance' => 1 ) );
		$this->stubLessons();
		$this->groupLessons->method( 'listGroupEndedBetween' )->willReturn( array( $lesson ) );

		$this->notifications->expects( self::never() )->method( 'push' );

		$this->service->tick();
	}
}
