<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\SubmissionDTO;
use Inc\DTO\Course\WorkDTO;
use Inc\Enums\Course\LessonKind;
use Inc\Enums\Course\SubmissionStatus;
use Inc\Enums\Course\WorkType;
use Inc\Managers\Course\LessonManager;
use Inc\Managers\Course\WorkManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;
use Inc\Services\Course\HomeworkDeadlineService;
use Inc\Services\Course\PassedWorksService;
use Inc\Services\Course\SubmissionService;
use Inc\Services\Course\TeacherGroupResolver;
use Inc\Services\Course\WorkMarksService;
use PHPUnit\Framework\TestCase;

/** Экран «Работы»: порции списка прошедших работ, ученики работы и зачёт несдавшим. */
class PassedWorksServiceTest extends TestCase {

	private TeacherGroupResolver&\PHPUnit\Framework\MockObject\MockObject $teacherGroups;
	private GroupLessonRepository&\PHPUnit\Framework\MockObject\MockObject $groupLessons;
	private StudentRecordRepository&\PHPUnit\Framework\MockObject\MockObject $records;
	private SubmissionRepository&\PHPUnit\Framework\MockObject\MockObject $submissions;
	private SubmissionService&\PHPUnit\Framework\MockObject\MockObject $submissionService;
	private HomeworkDeadlineService&\PHPUnit\Framework\MockObject\MockObject $deadlines;
	private WorkManager&\PHPUnit\Framework\MockObject\MockObject $works;
	private WorkMarksService&\PHPUnit\Framework\MockObject\MockObject $marks;
	private PassedWorksService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->teacherGroups     = $this->createMock( TeacherGroupResolver::class );
		$this->groupLessons      = $this->createMock( GroupLessonRepository::class );
		$this->records           = $this->createMock( StudentRecordRepository::class );
		$this->submissions       = $this->createMock( SubmissionRepository::class );
		$this->submissionService = $this->createMock( SubmissionService::class );
		$this->deadlines         = $this->createMock( HomeworkDeadlineService::class );
		$this->works             = $this->createMock( WorkManager::class );
		$this->marks             = $this->createMock( WorkMarksService::class );
		$groups                  = $this->createMock( GroupsRepository::class );
		$groups->method( 'findById' )->willReturn( (object) array( 'name' => 'Группа А' ) );
		$clock = $this->createMock( ClockInterface::class );
		$clock->method( 'now' )->willReturn( '2026-10-20 12:00:00' );

		$this->teacherGroups->method( 'idsFor' )->willReturn( array( 1 ) );
		$this->records->method( 'findActiveByGroupId' )->willReturn( array(
			(object) array( 'studentPersonId' => 10, 'snapshotLastName' => 'Иванов', 'snapshotFirstName' => 'Иван' ),
			(object) array( 'studentPersonId' => 11, 'snapshotLastName' => 'Петров', 'snapshotFirstName' => 'Пётр' ),
		) );

		$this->service = new PassedWorksService(
			$this->teacherGroups,
			$groups,
			$this->groupLessons,
			$this->records,
			$this->submissions,
			$this->submissionService,
			$this->deadlines,
			$this->createStub( LessonManager::class ),
			$this->works,
			$this->marks,
			$clock
		);
	}

	private function row( int $id = 5, int $groupId = 1, string $scheduledAt = '2026-10-19 10:00:00' ): GroupLessonDTO {
		return new GroupLessonDTO(
			id: $id, groupId: $groupId, lessonId: 10, position: 0, workIdsSnapshot: null, extraWorkIds: array(),
			scheduledAt: $scheduledAt, endsAt: null, isPinned: false, teacherUserId: null, visibility: 'open',
			openedAt: null, homeworkDueAt: null, allowLate: true, recordingUrl: null, createdByUserId: null, updatedByUserId: null
		);
	}

	/** @return array{row: GroupLessonDTO, at: string} */
	private function entry( int $id, string $at = '2026-10-19 10:00:00' ): array {
		return array( 'row' => $this->row( $id ), 'at' => $at );
	}

	/** Строка дополнительного занятия для части группы: по строке на ученика, общий `extra_key`. */
	private function extra( int $id, int $personId, string $scheduledAt = '2026-10-19 10:00:00' ): GroupLessonDTO {
		return new GroupLessonDTO(
			id: $id, groupId: 1, lessonId: 10, position: 0, workIdsSnapshot: null, extraWorkIds: array(),
			scheduledAt: $scheduledAt, endsAt: null, isPinned: true, teacherUserId: null, visibility: 'open',
			openedAt: null, homeworkDueAt: null, allowLate: true, recordingUrl: null, createdByUserId: null, updatedByUserId: null,
			kind: LessonKind::Individual, studentPersonId: $personId, extraKey: 'k1'
		);
	}

	/** Сдачи по строкам занятия: group_lesson_id => сдачи. */
	private function submissionsByRow( array $map ): void {
		$this->submissions->method( 'listAggregatesByGroupLesson' )->willReturnCallback( static fn( int $id ): array => $map[ $id ] ?? array() );
	}

	private function work( int $id = 3, WorkType $type = WorkType::Practice ): WorkDTO {
		return new WorkDTO( $id, 'inf', 'Работа ' . $id, $type, array( 101, 102 ), '', 1, 'publish' );
	}

	private function submission( int $personId, SubmissionStatus $status = SubmissionStatus::Graded, int $workId = 3 ): SubmissionDTO {
		return new SubmissionDTO( 70 + $personId, $personId, 5, $workId, WorkType::Practice, null, '{}', null, null, $status, 2.0, 2.0, null, null, '2026-10-19 11:00:00', null, '', '' );
	}

	// ── feed(): состав и счётчики ─────────────────────────────────────────────

	public function test_feed_counts_submitted_pending_and_missing_students(): void {
		$this->groupLessons->method( 'listFeedPage' )->willReturn( array( $this->entry( 5 ) ) );
		$this->deadlines->method( 'assignedWorks' )->willReturn( array( $this->work() ) );
		$this->submissions->method( 'listAggregatesByGroupLesson' )->willReturn( array( $this->submission( 10, SubmissionStatus::PendingReview ) ) );

		$feed = $this->service->feed( 7, false, 0, '', '', '' );

		self::assertCount( 1, $feed['items'] );
		$item = $feed['items'][0];
		self::assertSame( 2, $item['total'] );
		self::assertSame( 1, $item['submitted'] );
		self::assertSame( 1, $item['missing'] );
		self::assertSame( 1, $item['pending'] );
		self::assertSame( 'Группа А', $item['group_name'] );
		self::assertSame( '2026-10-19', $item['date'] );
		self::assertNull( $feed['next'] );
	}

	public function test_feed_does_not_count_graded_submission_as_pending(): void {
		$this->groupLessons->method( 'listFeedPage' )->willReturn( array( $this->entry( 5 ) ) );
		$this->deadlines->method( 'assignedWorks' )->willReturn( array( $this->work() ) );
		$this->submissions->method( 'listAggregatesByGroupLesson' )->willReturn( array( $this->submission( 10 ) ) );

		self::assertSame( 0, $this->service->feed( 7, false, 0, '', '', '' )['items'][0]['pending'] );
	}

	public function test_feed_keeps_work_with_submissions_even_if_removed_from_lesson_set(): void {
		$this->groupLessons->method( 'listFeedPage' )->willReturn( array( $this->entry( 5 ) ) );
		$this->deadlines->method( 'assignedWorks' )->willReturn( array() );
		$this->submissions->method( 'listAggregatesByGroupLesson' )->willReturn( array( $this->submission( 10, SubmissionStatus::Submitted, 9 ) ) );
		$this->works->method( 'get' )->with( 9 )->willReturn( $this->work( 9 ) );

		$items = $this->service->feed( 7, false, 0, '', '', '' )['items'];

		self::assertCount( 1, $items );
		self::assertSame( 9, $items[0]['work_id'] );
		self::assertSame( 1, $items[0]['pending'] );
	}

	public function test_feed_skips_lesson_without_works(): void {
		$this->groupLessons->method( 'listFeedPage' )->willReturn( array( $this->entry( 5 ) ) );
		$this->deadlines->method( 'assignedWorks' )->willReturn( array() );
		$this->submissions->method( 'listAggregatesByGroupLesson' )->willReturn( array() );

		self::assertSame( array(), $this->service->feed( 7, false, 0, '', '', '' )['items'] );
	}

	// ── feed(): фильтры ───────────────────────────────────────────────────────

	public function test_feed_filters_by_work_type(): void {
		$this->groupLessons->method( 'listFeedPage' )->willReturn( array( $this->entry( 5 ) ) );
		$this->deadlines->method( 'assignedWorks' )->willReturn( array( $this->work( 3 ), $this->work( 4, WorkType::Homework ) ) );
		$this->submissions->method( 'listAggregatesByGroupLesson' )->willReturn( array() );

		$items = $this->service->feed( 7, false, 0, 'homework', '', '' )['items'];

		self::assertCount( 1, $items );
		self::assertSame( 4, $items[0]['work_id'] );
	}

	public function test_feed_pending_filter_keeps_only_works_waiting_for_review(): void {
		$this->groupLessons->method( 'listFeedPage' )->willReturn( array( $this->entry( 5 ) ) );
		$this->deadlines->method( 'assignedWorks' )->willReturn( array( $this->work( 3 ), $this->work( 4 ) ) );
		$this->submissions->method( 'listAggregatesByGroupLesson' )->willReturn( array(
			$this->submission( 10, SubmissionStatus::PendingReview, 3 ),
			$this->submission( 10, SubmissionStatus::Graded, 4 ),
		) );

		$items = $this->service->feed( 7, false, 0, '', 'pending', '' )['items'];

		self::assertCount( 1, $items );
		self::assertSame( 3, $items[0]['work_id'] );
	}

	public function test_feed_missing_filter_drops_works_everyone_submitted(): void {
		$this->groupLessons->method( 'listFeedPage' )->willReturn( array( $this->entry( 5 ) ) );
		$this->deadlines->method( 'assignedWorks' )->willReturn( array( $this->work( 3 ), $this->work( 4 ) ) );
		$this->submissions->method( 'listAggregatesByGroupLesson' )->willReturn( array(
			$this->submission( 10, SubmissionStatus::Graded, 3 ),
			$this->submission( 11, SubmissionStatus::Graded, 3 ),
			$this->submission( 10, SubmissionStatus::Graded, 4 ),
		) );

		$items = $this->service->feed( 7, false, 0, '', 'missing', '' )['items'];

		self::assertCount( 1, $items );
		self::assertSame( 4, $items[0]['work_id'], 'работа 3 сдана обоими' );
	}

	public function test_feed_group_filter_outside_access_returns_nothing(): void {
		$this->groupLessons->expects( $this->never() )->method( 'listFeedPage' );

		$feed = $this->service->feed( 7, false, 99, '', '', '' );

		self::assertSame( array(), $feed['items'] );
		self::assertNull( $feed['next'] );
	}

	// ── Дополнительное занятие для части группы: одна работа на всех ─────────

	public function test_feed_shows_extra_lesson_as_one_work_for_all_its_students(): void {
		$first  = $this->extra( 6, 10 );
		$second = $this->extra( 5, 11 );
		$this->groupLessons->method( 'listFeedPage' )->willReturn( array(
			array( 'row' => $first, 'at' => '2026-10-19 10:00:00' ),
			array( 'row' => $second, 'at' => '2026-10-19 10:00:00' ),
		) );
		$this->groupLessons->method( 'listExtraSiblings' )->willReturn( array( $second, $first ) );
		$this->deadlines->method( 'assignedWorks' )->willReturn( array( $this->work() ) );
		$this->submissionsByRow( array( 6 => array( $this->submission( 10, SubmissionStatus::PendingReview ) ) ) );

		$items = $this->service->feed( 7, false, 0, '', '', '' )['items'];

		self::assertCount( 1, $items, 'четыре строки доп. занятия — одна работа, а не по карточке на ученика' );
		self::assertSame( 6, $items[0]['group_lesson_id'] );
		self::assertSame( 2, $items[0]['total'] );
		self::assertSame( 1, $items[0]['submitted'] );
		self::assertSame( 1, $items[0]['missing'] );
		self::assertSame( 1, $items[0]['pending'] );
	}

	public function test_feed_does_not_repeat_extra_lesson_on_the_next_page(): void {
		$first  = $this->extra( 6, 10 );
		$second = $this->extra( 5, 11 );
		// Страница началась со второй строки доп. занятия: первая осталась на прошлой странице.
		$this->groupLessons->method( 'listFeedPage' )->willReturn( array( array( 'row' => $second, 'at' => '2026-10-19 10:00:00' ) ) );
		$this->groupLessons->method( 'listExtraSiblings' )->willReturn( array( $second, $first ) );
		$this->deadlines->method( 'assignedWorks' )->willReturn( array( $this->work() ) );
		$this->submissionsByRow( array() );

		$feed = $this->service->feed( 7, false, 0, '', '', '2026-10-19 10:00:00|6' );

		self::assertSame( array(), $feed['items'] );
		self::assertNull( $feed['next'] );
	}

	public function test_feed_treats_extra_row_moved_to_another_time_as_its_own_lesson(): void {
		$moved = $this->extra( 6, 10, '2026-10-18 10:00:00' );
		$stays = $this->extra( 5, 11 );
		$this->groupLessons->method( 'listFeedPage' )->willReturn( array(
			array( 'row' => $stays, 'at' => '2026-10-19 10:00:00' ),
			array( 'row' => $moved, 'at' => '2026-10-18 10:00:00' ),
		) );
		$this->groupLessons->method( 'listExtraSiblings' )->willReturn( array( $stays, $moved ) );
		$this->deadlines->method( 'assignedWorks' )->willReturn( array( $this->work() ) );
		$this->submissionsByRow( array() );

		$items = $this->service->feed( 7, false, 0, '', '', '' )['items'];

		self::assertCount( 2, $items );
		self::assertSame( array( 1, 1 ), array( $items[0]['total'], $items[1]['total'] ) );
	}

	public function test_students_of_extra_lesson_come_from_all_its_rows_with_own_row_id(): void {
		$first  = $this->extra( 6, 10 );
		$second = $this->extra( 5, 11 );
		$this->groupLessons->method( 'find' )->willReturn( $second );
		$this->groupLessons->method( 'listExtraSiblings' )->willReturn( array( $second, $first ) );
		$this->submissionsByRow( array( 6 => array( $this->submission( 10 ) ) ) );

		$students = $this->service->students( 5, 3, 7, false );

		self::assertCount( 2, $students );
		self::assertSame( array( 10, 6, 'graded' ), array( $students[0]['student_person_id'], $students[0]['group_lesson_id'], $students[0]['status'] ) );
		self::assertSame( array( 11, 5, '' ), array( $students[1]['student_person_id'], $students[1]['group_lesson_id'], $students[1]['status'] ) );
	}

	public function test_credit_for_extra_lesson_goes_to_the_row_of_each_student(): void {
		$first  = $this->extra( 6, 10 );
		$second = $this->extra( 5, 11 );
		$this->groupLessons->method( 'find' )->willReturn( $second );
		$this->groupLessons->method( 'listExtraSiblings' )->willReturn( array( $second, $first ) );
		$this->submissionsByRow( array() );

		$calls = array();
		$this->submissionService->method( 'creditWork' )->willReturnCallback( static function ( int $personId, int $rowId ) use ( &$calls ): bool {
			$calls[ $personId ] = $rowId;
			return true;
		} );

		self::assertSame( 2, $this->service->credit( 5, 3, array(), 7, false ) );
		self::assertSame( array( 10 => 6, 11 => 5 ), $calls, 'зачёт пишется под строкой занятия самого ученика' );
	}

	public function test_uncredit_for_extra_lesson_goes_to_the_row_of_the_student(): void {
		$first  = $this->extra( 6, 10 );
		$second = $this->extra( 5, 11 );
		$this->groupLessons->method( 'find' )->willReturn( $second );
		$this->groupLessons->method( 'listExtraSiblings' )->willReturn( array( $second, $first ) );
		$this->submissionService->expects( $this->once() )->method( 'revokeWorkCredit' )->with( 10, 6, 3 )->willReturn( true );

		self::assertTrue( $this->service->uncredit( 5, 3, 10, 7, false ) );
	}

	// ── feed(): порции ────────────────────────────────────────────────────────

	public function test_feed_cuts_page_after_ten_works_and_gives_cursor_of_last_lesson(): void {
		$rows = array();
		for ( $id = 20; $id >= 9; $id-- ) {
			$rows[] = $this->entry( $id, sprintf( '2026-10-%02d 10:00:00', $id ) );
		}
		$this->groupLessons->method( 'listFeedPage' )->willReturn( $rows );
		$this->deadlines->method( 'assignedWorks' )->willReturn( array( $this->work() ) );
		$this->submissions->method( 'listAggregatesByGroupLesson' )->willReturn( array() );

		$feed = $this->service->feed( 7, false, 0, '', '', '' );

		self::assertCount( PassedWorksService::PAGE_SIZE, $feed['items'] );
		self::assertSame( '2026-10-11 10:00:00|11', $feed['next'], 'продолжение — после десятого занятия' );
	}

	public function test_feed_has_no_cursor_when_page_ends_exactly_with_last_lesson(): void {
		$rows = array();
		for ( $id = 20; $id >= 11; $id-- ) {
			$rows[] = $this->entry( $id, sprintf( '2026-10-%02d 10:00:00', $id ) );
		}
		$this->groupLessons->method( 'listFeedPage' )->willReturn( $rows );
		$this->deadlines->method( 'assignedWorks' )->willReturn( array( $this->work() ) );
		$this->submissions->method( 'listAggregatesByGroupLesson' )->willReturn( array() );

		$feed = $this->service->feed( 7, false, 0, '', '', '' );

		self::assertCount( 10, $feed['items'] );
		self::assertNull( $feed['next'], 'занятий больше нет' );
	}

	public function test_feed_closes_page_with_whole_lesson_so_it_can_exceed_ten_works(): void {
		$this->groupLessons->method( 'listFeedPage' )->willReturn( array( $this->entry( 8 ), $this->entry( 7 ) ) );
		$this->deadlines->method( 'assignedWorks' )->willReturn( array_map( fn( int $id ) => $this->work( $id ), range( 1, 12 ) ) );
		$this->submissions->method( 'listAggregatesByGroupLesson' )->willReturn( array() );

		$feed = $this->service->feed( 7, false, 0, '', '', '' );

		self::assertCount( 12, $feed['items'], 'занятие не рвётся посередине' );
		self::assertSame( '2026-10-19 10:00:00|8', $feed['next'] );
	}

	public function test_feed_returns_cursor_when_scan_limit_is_hit_without_matches(): void {
		$rows = array();
		for ( $id = 41; $id >= 1; $id-- ) {
			$rows[] = $this->entry( $id, sprintf( '2026-09-%02d 10:00:00', ( $id % 28 ) + 1 ) );
		}
		$this->groupLessons->method( 'listFeedPage' )->willReturn( $rows );
		$this->deadlines->method( 'assignedWorks' )->willReturn( array() );
		$this->submissions->method( 'listAggregatesByGroupLesson' )->willReturn( array() );

		$feed = $this->service->feed( 7, false, 0, '', '', '' );

		self::assertSame( array(), $feed['items'] );
		self::assertSame( '2026-09-03 10:00:00|2', $feed['next'], 'просмотрено 40 занятий из 41: метка на 40-м (id 2), клиент продолжит с 41-го' );
	}

	public function test_feed_passes_cursor_to_repository(): void {
		$this->groupLessons->expects( $this->once() )->method( 'listFeedPage' )
			->with( array( 1 ), '2026-10-20 12:00:00', '2026-10-11 10:00:00', 11, 41 )
			->willReturn( array() );

		$this->service->feed( 7, false, 0, '', '', '2026-10-11 10:00:00|11' );
	}

	public function test_feed_rejects_malformed_cursor(): void {
		$this->groupLessons->expects( $this->never() )->method( 'listFeedPage' );
		$this->expectException( \InvalidArgumentException::class );

		$this->service->feed( 7, false, 0, '', '', 'drop table' );
	}

	// ── feed(): число «ждут проверки» ─────────────────────────────────────────

	public function test_feed_reports_pending_total_only_for_the_first_page(): void {
		$this->groupLessons->method( 'listFeedPage' )->willReturn( array() );
		$this->submissions->expects( $this->once() )->method( 'summaryByGroups' )
			->with( array( 1 ), array( 'submitted', 'pending_review' ) )
			->willReturn( array( array( 'cnt' => 3 ), array( 'cnt' => 2 ) ) );

		self::assertSame( 5, $this->service->feed( 7, false, 0, '', '', '' )['pending_total'] );
		self::assertNull( $this->service->feed( 7, false, 0, '', '', '2026-10-11 10:00:00|11' )['pending_total'] );
	}

	// ── students(): статусы учеников ──────────────────────────────────────────

	public function test_students_carry_submission_status_and_empty_status_for_missing(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->row() );
		$this->submissions->method( 'listAggregatesByGroupLesson' )->willReturn( array( $this->submission( 10, SubmissionStatus::PendingReview ) ) );

		$students = $this->service->students( 5, 3, 7, false );

		self::assertSame( 'pending_review', $students[0]['status'] );
		self::assertSame( 'Иванов Иван', $students[0]['student_name'] );
		self::assertSame( '', $students[1]['status'] );
		self::assertSame( 0, $students[1]['submission_id'] );
	}

	public function test_students_with_submission_carry_marks_time_and_duration(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->row() );
		$this->submissions->method( 'listAggregatesByGroupLesson' )->willReturn( array( $this->submission( 10 ) ) );
		$this->marks->expects( $this->once() )->method( 'marksFor' )->with( 'submission', 80 )->willReturn( array( 'correct', 'incorrect' ) );

		$students = $this->service->students( 5, 3, 7, false );

		self::assertSame( array( 'correct', 'incorrect' ), $students[0]['marks'] );
		self::assertSame( '2026-10-19 11:00:00', $students[0]['submitted_at'] );
		self::assertSame( array(), $students[1]['marks'], 'у не сдавшего отметок нет' );
		self::assertNull( $students[1]['submitted_at'] );
	}

	// ── credit() ──────────────────────────────────────────────────────────────

	public function test_credit_without_ids_credits_only_students_without_submission(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->row() );
		$this->submissions->method( 'listAggregatesByGroupLesson' )->willReturn( array( $this->submission( 10 ) ) );
		$this->submissionService->expects( $this->once() )->method( 'creditWork' )->with( 11, 5, 3, 7 )->willReturn( true );

		self::assertSame( 1, $this->service->credit( 5, 3, array(), 7, false ) );
	}

	public function test_credit_is_denied_for_group_outside_access(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->row( 5, 99 ) );
		$this->submissionService->expects( $this->never() )->method( 'creditWork' );

		self::assertNull( $this->service->credit( 5, 3, array(), 7, false ) );
	}
}
