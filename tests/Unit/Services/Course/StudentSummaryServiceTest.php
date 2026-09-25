<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Course\GradebookEntryDTO;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\WorkDTO;
use Inc\Enums\Course\WorkType;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Services\Course\AttendanceService;
use Inc\Services\Course\EffectiveWorksResolver;
use Inc\Services\Course\GradebookService;
use Inc\Services\Course\HomeworkDeadlineService;
use Inc\Services\Course\LessonProgressService;
use Inc\Services\Course\StudentSummaryService;
use Inc\Services\Course\WorkMarksService;
use PHPUnit\Framework\TestCase;

/**
 * Несданные ДЗ с прошедшим дедлайном в «Сводке по ученику».
 */
class StudentSummaryServiceTest extends TestCase {

	private const int STUDENT = 7;

	private GroupLessonRepository&\PHPUnit\Framework\MockObject\Stub $groupLessons;
	private GradebookService&\PHPUnit\Framework\MockObject\Stub $gradebook;
	private EffectiveWorksResolver&\PHPUnit\Framework\MockObject\Stub $resolver;
	private StudentSummaryService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->groupLessons = $this->createStub( GroupLessonRepository::class );
		$this->gradebook    = $this->createStub( GradebookService::class );
		$this->resolver     = $this->createStub( EffectiveWorksResolver::class );

		$groups = $this->createStub( GroupsRepository::class );
		$groups->method( 'findById' )->willReturn( (object) array( 'access_mode' => 'scheduled' ) );

		$attendance = $this->createStub( AttendanceService::class );
		$attendance->method( 'matrixForGroup' )->willReturn( array() );

		$marks = $this->createStub( WorkMarksService::class );
		$marks->method( 'marksFor' )->willReturn( array( 'correct' ) );

		$clock = $this->createStub( ClockInterface::class );
		$clock->method( 'now' )->willReturn( '2026-09-25 12:00:00' );

$visibility = $this->createStub( \Inc\Services\Course\LessonVisibilityService::class );
		$visibility->method( 'effectiveVisibility' )->willReturnCallback( static fn( $row ) => $row->visibility );
		
		$this->service = new StudentSummaryService(
			$this->groupLessons,
			$this->createStub( LessonManager::class ),
			$attendance,
			$this->gradebook,
			$groups,
			$this->createStub( LessonProgressService::class ),
			$marks,
			new HomeworkDeadlineService( $this->resolver, $visibility, $this->groupLessons ),
			$clock,
		);
	}

	public function test_missed_homework_after_deadline_is_listed_with_dash_marks(): void {
		$this->groupLessons->method( 'listByGroup' )->willReturn( array( $this->glRow( 10, '2026-09-20 17:00:00' ) ) );
		$this->gradebook->method( 'forGroup' )->willReturn( array() );
		$this->resolver->method( 'resolve' )->willReturn( array( $this->work( 100, WorkType::Homework, 3 ) ) );

		$works = $this->service->forStudent( 5, self::STUDENT )['lessons'][0]['works'];

		self::assertCount( 1, $works );
		self::assertSame( 'missed', $works[0]['display'] );
		self::assertSame( 'ДЗ', $works[0]['badge'] );
		self::assertSame( array( 'missed', 'missed', 'missed' ), $works[0]['marks'] );
		self::assertSame( '2026-09-22 23:59:00', $works[0]['due_at'] );
		self::assertSame( 0, $works[0]['source_id'] );
	}

	public function test_submitted_homework_is_not_duplicated_as_missed(): void {
		$this->groupLessons->method( 'listByGroup' )->willReturn( array( $this->glRow( 10, '2026-09-20 17:00:00' ) ) );
		$this->gradebook->method( 'forGroup' )->willReturn( array( $this->entry( 10, 100 ) ) );
		$this->resolver->method( 'resolve' )->willReturn( array( $this->work( 100, WorkType::Homework, 3 ) ) );

		$works = $this->service->forStudent( 5, self::STUDENT )['lessons'][0]['works'];

		self::assertCount( 1, $works );
		self::assertSame( 'fraction', $works[0]['display'] );
	}

	public function test_homework_before_explicit_deadline_is_not_missed(): void {
		$this->groupLessons->method( 'listByGroup' )->willReturn( array(
			$this->glRow( 10, '2026-09-20 17:00:00', array( 100 => '2026-09-26 23:59:00' ) ),
			$this->glRow( 11, '2026-09-24 17:00:00', array( 100 => '2026-09-26 23:59:00' ) ),
		) );
		$this->gradebook->method( 'forGroup' )->willReturn( array() );
		$this->resolver->method( 'resolve' )->willReturn( array( $this->work( 100, WorkType::Homework, 3 ) ) );

		foreach ( $this->service->forStudent( 5, self::STUDENT )['lessons'] as $lesson ) {
			self::assertSame( array(), $lesson['works'] );
		}
	}

	public function test_homework_without_deadline_is_due_at_next_lesson_start(): void {
		$this->groupLessons->method( 'listByGroup' )->willReturn( array(
			$this->glRow( 10, '2026-09-18 17:00:00', array() ),
			$this->glRow( 11, '2026-09-21 17:00:00', array(), 'open', 'cancelled' ),
			$this->glRow( 12, '2026-09-22 17:00:00', array() ),
			$this->glRow( 13, '2026-09-26 17:00:00', array() ),
		) );
		$this->gradebook->method( 'forGroup' )->willReturn( array() );
		$this->resolver->method( 'resolve' )->willReturn( array( $this->work( 100, WorkType::Homework, 1 ) ) );

		$byId = array_column( $this->service->forStudent( 5, self::STUDENT )['lessons'], 'works', 'group_lesson_id' );

		// Отменённое занятие следующим не считается — срок уходит на 22-е.
		self::assertSame( '2026-09-22 17:00:00', $byId[10][0]['due_at'] );
		self::assertSame( array(), $byId[11] );
		// Срок — занятие 26-го, ещё не наступило.
		self::assertSame( array(), $byId[12] );
		// Следующего занятия нет — срока нет.
		self::assertSame( array(), $byId[13] );
	}

	public function test_classwork_and_hidden_or_cancelled_lessons_are_skipped(): void {
		$this->groupLessons->method( 'listByGroup' )->willReturn( array(
			$this->glRow( 10, '2026-09-20 17:00:00' ),
			$this->glRow( 11, '2026-09-19 17:00:00', null, 'hidden' ),
			$this->glRow( 12, '2026-09-18 17:00:00', null, 'open', 'cancelled' ),
		) );
		$this->gradebook->method( 'forGroup' )->willReturn( array() );
		$this->resolver->method( 'resolve' )->willReturnCallback(
			fn( GroupLessonDTO $gl ): array => 10 === $gl->id
				? array( $this->work( 101, WorkType::Practice, 2 ) )
				: array( $this->work( 100, WorkType::Homework, 3 ) )
		);

		foreach ( $this->service->forStudent( 5, self::STUDENT )['lessons'] as $lesson ) {
			self::assertSame( array(), $lesson['works'] );
		}
	}

	/** @param array<int,string>|null $deadlines null — дедлайн работы 100 через 2 дня после занятия */
	private function glRow( int $id, string $scheduledAt, ?array $deadlines = null, string $visibility = 'open', string $status = 'held' ): GroupLessonDTO {
		return new GroupLessonDTO(
			id: $id, groupId: 5, lessonId: null, position: 0, workIdsSnapshot: array(), extraWorkIds: array(),
			scheduledAt: $scheduledAt, endsAt: null, isPinned: false, teacherUserId: null, visibility: $visibility,
			openedAt: null, homeworkDueAt: null, allowLate: true, recordingUrl: null,
			createdByUserId: null, updatedByUserId: null, status: $status,
			workDeadlines: $deadlines ?? array( 100 => '2026-09-22 23:59:00' ),
		);
	}

	private function work( int $id, WorkType $type, int $tasks ): WorkDTO {
		return new WorkDTO(
			id: $id, subjectKey: 'inf', title: 'ДЗ ' . $id, workType: $type,
			itemIds: range( 1, $tasks ), instructions: '', authorId: 1, status: 'publish',
		);
	}

	private function entry( int $groupLessonId, int $workId ): GradebookEntryDTO {
		return new GradebookEntryDTO(
			studentPersonId: self::STUDENT, groupId: 5, sourceType: 'submission', sourceId: 55,
			title: 'ДЗ ' . $workId, category: 'homework', score: 2.0, maxScore: 3.0, gradedAt: null,
			displayType: 'fraction', groupLessonId: $groupLessonId, groupKey: 'work:' . $workId,
		);
	}
}
