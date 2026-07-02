<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\SubmissionDTO;
use Inc\Enums\Course\SubmissionStatus;
use Inc\Enums\Course\WorkType;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;
use Inc\Services\Course\SubmissionGradeSource;
use PHPUnit\Framework\TestCase;

class SubmissionGradeSourceTest extends TestCase {

	private SubmissionRepository&\PHPUnit\Framework\MockObject\MockObject $submissions;
	private GroupLessonRepository&\PHPUnit\Framework\MockObject\MockObject $groupLessons;
	private LessonManager&\PHPUnit\Framework\MockObject\MockObject $lessonManager;
	private SubmissionGradeSource $source;

	protected function setUp(): void {
		parent::setUp();
		$this->submissions   = $this->createMock( SubmissionRepository::class );
		$this->groupLessons  = $this->createMock( GroupLessonRepository::class );
		$this->lessonManager = $this->createMock( LessonManager::class );
		$this->source = new SubmissionGradeSource( $this->submissions, $this->groupLessons, $this->lessonManager );
	}

	private function sub( ?string $submittedAt, ?string $dueAt ): SubmissionDTO {
		return new SubmissionDTO(
			id: 1, studentPersonId: 10, groupLessonId: 5, workId: 3, workType: WorkType::Practice,
			taskId: null, answerText: 'x', attachmentId: null, dueAt: $dueAt,
			status: SubmissionStatus::Graded, score: 5.0, maxScore: 5.0, feedback: null,
			gradedByUserId: null, submittedAt: $submittedAt, gradedAt: null,
			createdAt: '', updatedAt: '',
		);
	}

	private function row(): GroupLessonDTO {
		return new GroupLessonDTO(
			id: 5, groupId: 1, lessonId: null, position: 0, workIdsSnapshot: null, extraWorkIds: [],
			scheduledAt: null, endsAt: null, isPinned: false, teacherUserId: null, visibility: 'open',
			openedAt: null, homeworkDueAt: null, allowLate: true, recordingUrl: null,
			createdByUserId: null, updatedByUserId: null,
		);
	}

	/** T12.2 (D13): постоянная метка «Просрочено» пробрасывается из SubmissionDTO::isLate(). */
	public function test_entries_for_group_propagates_is_late(): void {
		$this->submissions->method( 'listForGradebookByGroup' )->willReturn( array(
			$this->sub( '2026-06-05 10:00:00', '2026-06-01 00:00:00' ), // late
		) );
		$this->groupLessons->method( 'find' )->willReturn( $this->row() );

		$entries = $this->source->entriesForGroup( 1 );

		self::assertCount( 1, $entries );
		self::assertTrue( $entries[0]->isLate );
	}

	public function test_entries_for_group_not_late_when_submitted_on_time(): void {
		$this->submissions->method( 'listForGradebookByGroup' )->willReturn( array(
			$this->sub( '2026-05-30 10:00:00', '2026-06-01 00:00:00' ), // on time
		) );
		$this->groupLessons->method( 'find' )->willReturn( $this->row() );

		$entries = $this->source->entriesForGroup( 1 );

		self::assertFalse( $entries[0]->isLate );
	}
}
