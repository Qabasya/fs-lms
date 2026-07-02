<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\DTO\Course\SubmissionDTO;
use Inc\Enums\Course\SubmissionStatus;
use Inc\Enums\Course\WorkType;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Managers\Course\WorkManager;
use Inc\Managers\Wp\MediaManager;
use Inc\Managers\Wp\PostManager;
use Inc\Repositories\WPDBRepositories\AssessmentAnswerRepository;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;
use Inc\Services\Course\WorkDetailService;
use Inc\Services\Task\CorrectAnswerResolver;
use PHPUnit\Framework\TestCase;

/** T13.1: вложение ученика (фото/файл решения) в детали работы для учителя. */
class WorkDetailServiceTest extends TestCase {

	private SubmissionRepository&\PHPUnit\Framework\MockObject\MockObject $submissions;
	private WorkManager&\PHPUnit\Framework\MockObject\MockObject $works;
	private GroupLessonRepository&\PHPUnit\Framework\MockObject\MockObject $groupLessons;
	private MediaManager&\PHPUnit\Framework\MockObject\MockObject $media;
	private WorkDetailService $service;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_fs_test_post_mime_types'] = array();
		$this->submissions  = $this->createMock( SubmissionRepository::class );
		$this->works        = $this->createMock( WorkManager::class );
		$this->groupLessons = $this->createMock( GroupLessonRepository::class );
		$this->media        = $this->createMock( MediaManager::class );
		$this->service = new WorkDetailService(
			$this->submissions,
			$this->works,
			$this->createMock( PostManager::class ),
			$this->groupLessons,
			$this->createMock( AssessmentAttemptRepository::class ),
			$this->createMock( AssessmentAnswerRepository::class ),
			$this->createMock( AssessmentManager::class ),
			$this->createMock( CorrectAnswerResolver::class ),
			$this->media,
		);
	}

	private function sub( ?int $attachmentId ): SubmissionDTO {
		return new SubmissionDTO(
			id: 7, studentPersonId: 10, groupLessonId: 5, workId: 3, workType: WorkType::Practice,
			taskId: null, answerText: 'freeform answer', attachmentId: $attachmentId, dueAt: null,
			status: SubmissionStatus::Submitted, score: null, maxScore: null, feedback: null,
			gradedByUserId: null, submittedAt: '2026-06-01 10:00:00', gradedAt: null,
			createdAt: '', updatedAt: '',
		);
	}

	public function test_from_work_includes_attachment_url_and_mime_when_present(): void {
		$this->submissions->method( 'find' )->willReturn( $this->sub( 501 ) );
		$this->submissions->method( 'listPerTaskByStudentWorkLesson' )->willReturn( array() );
		$this->media->method( 'url' )->with( 501 )->willReturn( 'https://example.test/wp-content/uploads/photo.jpg' );
		$GLOBALS['_fs_test_post_mime_types'][501] = 'image/jpeg';

		$detail = $this->service->forWork( 'submission', 7 );

		self::assertSame( 'https://example.test/wp-content/uploads/photo.jpg', $detail['attachment_url'] );
		self::assertSame( 'image/jpeg', $detail['attachment_mime'] );
	}

	public function test_from_work_attachment_fields_null_when_no_attachment(): void {
		$this->submissions->method( 'find' )->willReturn( $this->sub( null ) );
		$this->submissions->method( 'listPerTaskByStudentWorkLesson' )->willReturn( array() );
		$this->media->expects( $this->never() )->method( 'url' );

		$detail = $this->service->forWork( 'submission', 7 );

		self::assertNull( $detail['attachment_url'] );
		self::assertNull( $detail['attachment_mime'] );
	}
}
