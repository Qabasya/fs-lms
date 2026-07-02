<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\DTO\Assessment\AttemptAnswerDTO;
use Inc\DTO\Assessment\AttemptDTO;
use Inc\DTO\Course\SubmissionDTO;
use Inc\Enums\Course\SubmissionStatus;
use Inc\Enums\Course\WorkType;
use Inc\Enums\Wp\PostMetaName;
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

/**
 * T13.1: вложение ученика (фото/файл решения) в детали работы для учителя.
 * Эпик 13 (T13.6): «Развёрнутый ответ» в контрольных — файлы + критерии (D17).
 */
class WorkDetailServiceTest extends TestCase {

	private SubmissionRepository&\PHPUnit\Framework\MockObject\MockObject        $submissions;
	private WorkManager&\PHPUnit\Framework\MockObject\MockObject                 $works;
	private PostManager&\PHPUnit\Framework\MockObject\MockObject                 $posts;
	private GroupLessonRepository&\PHPUnit\Framework\MockObject\MockObject       $groupLessons;
	private AssessmentAttemptRepository&\PHPUnit\Framework\MockObject\MockObject $attempts;
	private AssessmentAnswerRepository&\PHPUnit\Framework\MockObject\MockObject  $answers;
	private AssessmentManager&\PHPUnit\Framework\MockObject\MockObject           $assessments;
	private MediaManager&\PHPUnit\Framework\MockObject\MockObject                $media;
	private WorkDetailService $service;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_fs_test_post_mime_types'] = array();
		$this->submissions  = $this->createMock( SubmissionRepository::class );
		$this->works        = $this->createMock( WorkManager::class );
		$this->posts        = $this->createMock( PostManager::class );
		$this->groupLessons = $this->createMock( GroupLessonRepository::class );
		$this->attempts     = $this->createMock( AssessmentAttemptRepository::class );
		$this->answers      = $this->createMock( AssessmentAnswerRepository::class );
		$this->assessments  = $this->createMock( AssessmentManager::class );
		$this->media        = $this->createMock( MediaManager::class );
		$this->service = new WorkDetailService(
			$this->submissions,
			$this->works,
			$this->posts,
			$this->groupLessons,
			$this->attempts,
			$this->answers,
			$this->assessments,
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

	/* ── fromAttempt: «Развёрнутый ответ» — файлы + критерии (Эпик 13, T13.6) ── */

	private function attemptFixture(): AttemptDTO {
		return AttemptDTO::fromArray( array(
			'id' => 9, 'assessment_id' => 1, 'student_person_id' => 10, 'group_id' => null,
			'attempt_number' => 1, 'started_at' => '2026-06-01 10:00:00', 'deadline_at' => '2026-06-01 11:00:00',
			'status' => 'submitted',
		) );
	}

	public function test_from_attempt_file_answer_task_parses_text_and_resolves_files(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attemptFixture() );
		$this->answers->method( 'listByAttempt' )->willReturn( array(
			AttemptAnswerDTO::fromArray( array(
				'id' => 1, 'attempt_id' => 9, 'task_id' => 42,
				'answer_text' => '{"text":"Мой ответ","files":[501,502]}',
			) ),
		) );
		$this->posts->method( 'getMeta' )->willReturnCallback( function ( int $postId, string $key ) {
			if ( PostMetaName::TemplateType->value === $key ) {
				return 'file_answer_task';
			}
			return array(); // task_criteria отсутствуют
		} );
		$this->media->method( 'url' )->willReturnMap( array(
			array( 501, 'https://example.test/a.jpg' ),
			array( 502, 'https://example.test/b.py' ),
		) );
		$GLOBALS['_fs_test_post_mime_types'] = array( 501 => 'image/jpeg', 502 => 'text/x-python' );

		$detail = $this->service->forWork( 'attempt', 9 );
		$task   = $detail['tasks'][0];

		self::assertSame( 'Мой ответ', $task['answer'] );
		self::assertCount( 2, $task['files'] );
		self::assertSame( 'https://example.test/a.jpg', $task['files'][0]['url'] );
		self::assertSame( 'image/jpeg', $task['files'][0]['mime'] );
		self::assertSame( array(), $task['criteria'] );
	}

	public function test_from_attempt_non_file_answer_task_leaves_answer_and_files_untouched(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attemptFixture() );
		$this->answers->method( 'listByAttempt' )->willReturn( array(
			AttemptAnswerDTO::fromArray( array(
				'id' => 1, 'attempt_id' => 9, 'task_id' => 42,
				'answer_text' => 'plain text answer',
			) ),
		) );
		$this->posts->method( 'getMeta' )->willReturnCallback( function ( int $postId, string $key ) {
			if ( PostMetaName::TemplateType->value === $key ) {
				return 'standard_task';
			}
			return array();
		} );
		$this->media->expects( $this->never() )->method( 'url' );

		$task = $this->service->forWork( 'attempt', 9 )['tasks'][0];

		self::assertSame( 'plain text answer', $task['answer'] );
		self::assertSame( array(), $task['files'] );
	}

	public function test_from_attempt_exposes_criteria_with_awarded_points(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attemptFixture() );
		$this->answers->method( 'listByAttempt' )->willReturn( array(
			AttemptAnswerDTO::fromArray( array(
				'id' => 1, 'attempt_id' => 9, 'task_id' => 42,
				'answer_text' => '{"text":"","files":[]}',
				'criteria_scores' => '[2,0.5]',
			) ),
		) );
		$this->posts->method( 'getMeta' )->willReturnCallback( function ( int $postId, string $key ) {
			if ( PostMetaName::TemplateType->value === $key ) {
				return 'file_answer_task';
			}
			return array( 'task_criteria' => array( 'criteria' => array(
				array( 'label' => 'К1', 'max_points' => 2 ),
				array( 'label' => 'К2', 'max_points' => 1 ),
			) ) );
		} );

		$criteria = $this->service->forWork( 'attempt', 9 )['tasks'][0]['criteria'];

		self::assertSame( array(
			array( 'label' => 'К1', 'max_points' => 2.0, 'awarded' => 2.0 ),
			array( 'label' => 'К2', 'max_points' => 1.0, 'awarded' => 0.5 ),
		), $criteria );
	}

	public function test_from_attempt_criteria_awarded_null_when_not_yet_graded(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attemptFixture() );
		$this->answers->method( 'listByAttempt' )->willReturn( array(
			AttemptAnswerDTO::fromArray( array(
				'id' => 1, 'attempt_id' => 9, 'task_id' => 42, 'answer_text' => '{"text":"x","files":[]}',
			) ),
		) );
		$this->posts->method( 'getMeta' )->willReturnCallback( function ( int $postId, string $key ) {
			if ( PostMetaName::TemplateType->value === $key ) {
				return 'file_answer_task';
			}
			return array( 'task_criteria' => array( 'criteria' => array(
				array( 'label' => 'К1', 'max_points' => 2 ),
			) ) );
		} );

		$criteria = $this->service->forWork( 'attempt', 9 )['tasks'][0]['criteria'];

		self::assertNull( $criteria[0]['awarded'] );
	}
}
