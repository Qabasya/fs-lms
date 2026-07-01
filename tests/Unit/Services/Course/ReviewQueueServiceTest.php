<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\DTO\Course\SubmissionDTO;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;
use Inc\Services\Course\ReviewQueueService;
use PHPUnit\Framework\TestCase;

class ReviewQueueServiceTest extends TestCase {

	private SubmissionRepository    $submissions;
	private StudentRecordRepository $records;
	private GroupLessonRepository   $groupLessons;
	private LessonManager           $lessons;
	private ReviewQueueService      $service;

	protected function setUp(): void {
		parent::setUp();
		$this->submissions  = $this->createMock( SubmissionRepository::class );
		$this->records      = $this->createMock( StudentRecordRepository::class );
		$this->groupLessons = $this->createMock( GroupLessonRepository::class );
		$this->lessons      = $this->createMock( LessonManager::class );
		$this->service      = new ReviewQueueService(
			$this->submissions, $this->records, $this->groupLessons, $this->lessons
		);
	}

	public function test_empty_queue_returns_empty_array(): void {
		$this->submissions->method( 'listQueueByGroup' )->willReturn( array() );
		$this->records->method( 'findActiveByGroupId' )->willReturn( array() );
		$this->groupLessons->method( 'listByGroup' )->willReturn( array() );

		self::assertSame( array(), $this->service->forGroup( 1 ) );
	}

	public function test_enriches_with_student_name_and_lesson_topic(): void {
		$this->submissions->method( 'listQueueByGroup' )->willReturn( array( $this->submission( 42, 9001, 7 ) ) );
		$this->records->method( 'findActiveByGroupId' )->willReturn( array(
			(object) array( 'studentPersonId' => 9001, 'snapshotLastName' => 'Иванов', 'snapshotFirstName' => 'Пётр' ),
		) );
		// lessonId null → тема берётся из label занятия, LessonManager::get не вызывается.
		$this->lessons->expects( $this->never() )->method( 'get' );
		$this->groupLessons->method( 'listByGroup' )->willReturn( array(
			(object) array( 'id' => 7, 'lessonId' => null, 'label' => 'Вводное занятие' ),
		) );

		$row = $this->service->forGroup( 1 )[0];

		self::assertSame( 42, $row['id'] );
		self::assertSame( 9001, $row['student_person_id'] );
		self::assertSame( 'Иванов Пётр', $row['student_name'] );
		self::assertSame( 'Вводное занятие', $row['lesson_topic'] );
		self::assertSame( 'practice', $row['work_type'] );
		self::assertArrayHasKey( 'work_type_label', $row );
		self::assertFalse( $row['is_late'] );
	}

	public function test_unknown_student_falls_back_to_id_label(): void {
		$this->submissions->method( 'listQueueByGroup' )->willReturn( array( $this->submission( 1, 9999, 7 ) ) );
		$this->records->method( 'findActiveByGroupId' )->willReturn( array() );
		$this->groupLessons->method( 'listByGroup' )->willReturn( array() );

		$row = $this->service->forGroup( 1 )[0];

		self::assertSame( 'Ученик #9999', $row['student_name'] );
		self::assertSame( '', $row['lesson_topic'] );
	}

	private function submission( int $id, int $studentPersonId, int $groupLessonId ): SubmissionDTO {
		return SubmissionDTO::fromArray( array(
			'id'                => $id,
			'student_person_id' => $studentPersonId,
			'group_lesson_id'   => $groupLessonId,
			'work_id'           => 5,
			'work_type'         => 'practice',
			'task_id'           => null,
			'answer_text'       => 'ответ',
			'attachment_id'     => null,
			'due_at'            => null,
			'status'            => 'submitted',
			'score'             => null,
			'max_score'         => 10,
			'feedback'          => null,
			'graded_by_user_id' => null,
			'submitted_at'      => '2026-06-30 12:00:00',
			'graded_at'         => null,
			'created_at'        => '2026-06-30 12:00:00',
			'updated_at'        => '2026-06-30 12:00:00',
		) );
	}
}
