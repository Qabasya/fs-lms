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
use Inc\Repositories\WPDBRepositories\TaskAttemptRepository;
use Inc\Services\Course\WorkDetailService;
use Inc\Services\Task\CorrectAnswerResolver;
use Inc\Services\Task\TaskMetaService;
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
	private TaskAttemptRepository&\PHPUnit\Framework\MockObject\MockObject       $taskAttempts;
	private CorrectAnswerResolver&\PHPUnit\Framework\MockObject\MockObject       $correctAnswers;
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
		$this->taskAttempts   = $this->createMock( TaskAttemptRepository::class );
		$this->correctAnswers = $this->createMock( CorrectAnswerResolver::class );
		$this->service = new WorkDetailService(
			$this->submissions,
			$this->works,
			$this->posts,
			$this->groupLessons,
			$this->attempts,
			$this->answers,
			$this->assessments,
			$this->correctAnswers,
			$this->media,
			new TaskMetaService(),
			$this->taskAttempts,
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

	/** D1 (Tasks.md, блок D): условие задания читается из меты, не из пустого post_content. */
	public function test_from_submission_condition_read_from_task_meta(): void {
		$this->submissions->method( 'find' )->willReturn( $this->sub( null ) );
		$this->submissions->method( 'listPerTaskByStudentWorkLesson' )->willReturn( array() );
		$this->works->method( 'get' )->willReturn( new \Inc\DTO\Course\WorkDTO(
			id: 3, subjectKey: 'inf', title: 'Работа', workType: WorkType::Practice,
			itemIds: array( 42 ), instructions: '', authorId: 1, status: 'publish',
		) );
		$this->posts->method( 'taskMeta' )->with( 42 )->willReturn( array( 'task_condition' => 'Условие из меты' ) );
		$this->posts->expects( $this->never() )->method( 'get' );

		$task = $this->service->forWork( 'submission', 7 )['tasks'][0];

		self::assertSame( 'Условие из меты', $task['condition'] );
	}

	/* ── D4 (.docs/Tasks.md): submission-работы оцениваются поштучно, как экзамены ── */

	private function perTaskSub( int $id, int $taskId, array $overrides = array() ): SubmissionDTO {
		return new SubmissionDTO(
			id: $id, studentPersonId: 10, groupLessonId: 5, workId: 3, workType: WorkType::Practice,
			taskId: $taskId,
			answerText: $overrides['answerText'] ?? 'ответ ученика',
			attachmentId: null, dueAt: null,
			status: $overrides['status'] ?? SubmissionStatus::Submitted,
			score: $overrides['score'] ?? null, maxScore: $overrides['maxScore'] ?? null,
			feedback: $overrides['feedback'] ?? null,
			gradedByUserId: $overrides['gradedByUserId'] ?? null,
			submittedAt: '2026-06-01 10:00:00', gradedAt: null, createdAt: '', updatedAt: '',
		);
	}

	private function workWithItems( array $itemIds ): \Inc\DTO\Course\WorkDTO {
		return new \Inc\DTO\Course\WorkDTO(
			id: 3, subjectKey: 'inf', title: 'Работа', workType: WorkType::Practice,
			itemIds: $itemIds, instructions: '', authorId: 1, status: 'publish',
		);
	}

	public function test_from_submission_marks_file_answer_task_as_gradable_with_submission_id(): void {
		$this->submissions->method( 'find' )->willReturn( $this->sub( null ) );
		$this->submissions->method( 'listPerTaskByStudentWorkLesson' )->willReturn( array(
			$this->perTaskSub( 501, 42, array( 'status' => SubmissionStatus::PendingReview ) ),
		) );
		$this->works->method( 'get' )->willReturn( $this->workWithItems( array( 42 ) ) );
		$this->posts->method( 'getMeta' )->with( 42, PostMetaName::TemplateType->value )->willReturn( 'file_answer_task' );
		$this->posts->method( 'taskMeta' )->willReturn( array() );

		$task = $this->service->forWork( 'submission', 7 )['tasks'][0];

		self::assertTrue( $task['gradable'] );
		self::assertSame( 501, $task['task_submission_id'] );
		self::assertSame( 'pending', $task['verdict'] );
	}

	/* ── Tasks.md, п. 6: ручной зачёт задания работы ── */

	/**
	 * Автопроверенная задача тоже отдаёт `task_submission_id` — без него экран
	 * не может показать кнопку «Засчитать»; `manually_graded` при этом ложный,
	 * пока преподаватель не вмешался.
	 */
	public function test_from_submission_auto_task_exposes_submission_id_without_manual_mark(): void {
		$this->submissions->method( 'find' )->willReturn( $this->sub( null ) );
		$this->submissions->method( 'listPerTaskByStudentWorkLesson' )->willReturn( array(
			$this->perTaskSub( 501, 42, array( 'status' => SubmissionStatus::Graded, 'score' => 0.0, 'maxScore' => 1.0 ) ),
		) );
		$this->works->method( 'get' )->willReturn( $this->workWithItems( array( 42 ) ) );
		$this->posts->method( 'getMeta' )->willReturn( 'standard_task' );
		$this->posts->method( 'taskMeta' )->willReturn( array() );

		$task = $this->service->forWork( 'submission', 7 )['tasks'][0];

		self::assertFalse( $task['gradable'] );
		self::assertSame( 501, $task['task_submission_id'] );
		self::assertFalse( $task['manually_graded'] );
	}

	/**
	 * Маркер ручного вмешательства — непустой `graded_by_user_id`: авто-проверка
	 * при сдаче его не пишет, отдельная колонка не нужна.
	 */
	public function test_from_submission_flags_manually_graded_auto_task(): void {
		$this->submissions->method( 'find' )->willReturn( $this->sub( null ) );
		$this->submissions->method( 'listPerTaskByStudentWorkLesson' )->willReturn( array(
			$this->perTaskSub( 501, 42, array(
				'status' => SubmissionStatus::Graded, 'score' => 1.0, 'maxScore' => 1.0, 'gradedByUserId' => 99,
			) ),
		) );
		$this->works->method( 'get' )->willReturn( $this->workWithItems( array( 42 ) ) );
		$this->posts->method( 'getMeta' )->willReturn( 'standard_task' );
		$this->posts->method( 'taskMeta' )->willReturn( array() );

		$task = $this->service->forWork( 'submission', 7 )['tasks'][0];

		self::assertTrue( $task['manually_graded'] );
	}

	/**
	 * Снапшот агрегата пишется один раз при сдаче: у сдач, сделанных до появления
	 * его синхронизации, зачёта в нём нет — поэтому ручная строка авторитетнее.
	 */
	public function test_from_submission_manual_row_overrides_stale_snapshot(): void {
		$stale = $this->sub( null );
		$this->submissions->method( 'find' )->willReturn( new SubmissionDTO(
			id: 7, studentPersonId: 10, groupLessonId: 5, workId: 3, workType: WorkType::Practice,
			taskId: null,
			answerText: json_encode( array( 42 => array( 'verdict' => 'incorrect', 'score' => 0.0, 'maxScore' => 1.0 ) ) ),
			attachmentId: null, dueAt: null, status: $stale->status, score: null, maxScore: null,
			feedback: null, gradedByUserId: null, submittedAt: '2026-06-01 10:00:00', gradedAt: null,
			createdAt: '', updatedAt: '',
		) );
		$this->submissions->method( 'listPerTaskByStudentWorkLesson' )->willReturn( array(
			$this->perTaskSub( 501, 42, array(
				'status' => SubmissionStatus::Graded, 'score' => 1.0, 'maxScore' => 1.0,
				'gradedByUserId' => 99, 'feedback' => 'Опечатка в условии',
			) ),
		) );
		$this->works->method( 'get' )->willReturn( $this->workWithItems( array( 42 ) ) );
		$this->posts->method( 'getMeta' )->willReturn( 'standard_task' );
		$this->posts->method( 'taskMeta' )->willReturn( array() );

		$task = $this->service->forWork( 'submission', 7 )['tasks'][0];

		self::assertSame( 'correct', $task['verdict'] );
		self::assertSame( 1.0, $task['score'] );
		self::assertSame( 'Опечатка в условии', $task['feedback'] );
	}

	public function test_from_submission_graded_file_answer_task_uses_per_task_row_as_authoritative(): void {
		$this->submissions->method( 'find' )->willReturn( $this->sub( null ) );
		$this->submissions->method( 'listPerTaskByStudentWorkLesson' )->willReturn( array(
			$this->perTaskSub( 501, 42, array(
				'status' => SubmissionStatus::Graded, 'score' => 1.0, 'maxScore' => 1.0, 'feedback' => 'Молодец',
			) ),
		) );
		$this->works->method( 'get' )->willReturn( $this->workWithItems( array( 42 ) ) );
		$this->posts->method( 'getMeta' )->willReturn( 'file_answer_task' );
		$this->posts->method( 'taskMeta' )->willReturn( array() );

		$task = $this->service->forWork( 'submission', 7 )['tasks'][0];

		self::assertSame( 'correct', $task['verdict'] );
		self::assertSame( 1.0, $task['score'] );
		self::assertSame( 'Молодец', $task['feedback'] );
	}

	public function test_from_submission_non_gradable_task_has_no_task_grading_target(): void {
		$this->submissions->method( 'find' )->willReturn( $this->sub( null ) );
		$this->submissions->method( 'listPerTaskByStudentWorkLesson' )->willReturn( array(
			$this->perTaskSub( 501, 42 ),
		) );
		$this->works->method( 'get' )->willReturn( $this->workWithItems( array( 42 ) ) );
		$this->posts->method( 'getMeta' )->willReturn( 'standard_task' );
		$this->posts->method( 'taskMeta' )->willReturn( array() );

		$task = $this->service->forWork( 'submission', 7 )['tasks'][0];

		self::assertFalse( $task['gradable'] );
	}

	/** Разбор по заданиям (itemIds непуст) — единая форма оценивания больше не нужна. */
	public function test_from_submission_whole_form_disabled_when_work_has_items(): void {
		$this->submissions->method( 'find' )->willReturn( $this->sub( null ) );
		$this->submissions->method( 'listPerTaskByStudentWorkLesson' )->willReturn( array(
			$this->perTaskSub( 501, 42 ),
		) );
		$this->works->method( 'get' )->willReturn( $this->workWithItems( array( 42 ) ) );
		$this->posts->method( 'getMeta' )->willReturn( 'standard_task' );
		$this->posts->method( 'taskMeta' )->willReturn( array() );

		$detail = $this->service->forWork( 'submission', 7 );

		self::assertFalse( $detail['gradable'] );
	}

	/** Фолбэк свободного ответа (без разбора на задачи) — старая форма сохраняется. */
	public function test_from_submission_whole_form_enabled_for_freeform_fallback(): void {
		$freeform = new SubmissionDTO(
			id: 7, studentPersonId: 10, groupLessonId: 5, workId: 3, workType: WorkType::Practice,
			taskId: null, answerText: 'свободный ответ', attachmentId: null, dueAt: null,
			status: SubmissionStatus::Submitted, score: null, maxScore: null, feedback: null,
			gradedByUserId: null, submittedAt: '2026-06-01 10:00:00', gradedAt: null, createdAt: '', updatedAt: '',
		);
		$this->submissions->method( 'find' )->willReturn( $freeform );
		$this->submissions->method( 'listPerTaskByStudentWorkLesson' )->willReturn( array() );
		$this->works->method( 'get' )->willReturn( $this->workWithItems( array() ) );

		$detail = $this->service->forWork( 'submission', 7 );

		self::assertTrue( $detail['gradable'] );
		self::assertFalse( $detail['tasks'][0]['gradable'] );
	}

	/* ── fromAttempt: «Развёрнутый ответ» — файлы + критерии (Эпик 13, T13.6) ── */

	private function attemptFixture(): AttemptDTO {
		return AttemptDTO::fromArray( array(
			'id' => 9, 'assessment_id' => 1, 'student_person_id' => 10, 'group_id' => null,
			'attempt_number' => 1, 'started_at' => '2026-06-01 10:00:00', 'deadline_at' => '2026-06-01 11:00:00',
			'status' => 'submitted',
		) );
	}

	private function assessmentFixture(): \Inc\DTO\Assessment\AssessmentDTO {
		return new \Inc\DTO\Assessment\AssessmentDTO(
			id: 1, subjectKey: 'inf', title: 'ОГЭ', taskIds: array( 42 ),
			timeLimit: 0, attemptsAllowed: 0, passScore: 0.0,
			scoringPolicy: \Inc\Enums\Assessment\ScoringPolicy::Highest, status: 'publish',
			kind: \Inc\Enums\Assessment\AssessmentKind::OgeComputer, taskPoints: array(), scoreMap: array(),
		);
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

	/** D1 (Tasks.md, блок D): условие задания читается из меты и в ветке экзамена. */
	public function test_from_attempt_condition_read_from_task_meta(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attemptFixture() );
		$this->answers->method( 'listByAttempt' )->willReturn( array(
			AttemptAnswerDTO::fromArray( array(
				'id' => 1, 'attempt_id' => 9, 'task_id' => 42, 'answer_text' => 'x',
			) ),
		) );
		$this->posts->method( 'getMeta' )->willReturnCallback( function ( int $postId, string $key ) {
			return PostMetaName::TemplateType->value === $key ? 'standard_task' : array();
		} );
		$this->posts->method( 'taskMeta' )->with( 42 )->willReturn( array( 'task_condition' => 'Условие экзамена' ) );
		$this->posts->expects( $this->never() )->method( 'get' );

		$task = $this->service->forWork( 'attempt', 9 )['tasks'][0];

		self::assertSame( 'Условие экзамена', $task['condition'] );
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

	/* ── fromAttempt: oge_rubric (holistic-рубрика ОГЭ 13-16, §3.4) ── */

	public function test_from_attempt_exposes_oge_rubric_via_module_filter(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attemptFixture() );
		$this->answers->method( 'listByAttempt' )->willReturn( array(
			AttemptAnswerDTO::fromArray( array(
				'id' => 1, 'attempt_id' => 9, 'task_id' => 42, 'answer_text' => '{"text":"","files":[]}',
			) ),
		) );
		$this->posts->method( 'getMeta' )->willReturnCallback( function ( int $postId, string $key ) {
			return PostMetaName::TemplateType->value === $key ? 'file_answer_task' : array();
		} );
		$this->assessments->method( 'get' )->willReturn( $this->assessmentFixture() );

		$GLOBALS['_fs_test_filter_returns'][ WorkDetailService::OGE_RUBRIC_FILTER ] = array(
			'max_points' => 3, 'html' => '<div>рубрика</div>',
		);

		$task = $this->service->forWork( 'attempt', 9 )['tasks'][0];

		unset( $GLOBALS['_fs_test_filter_returns'][ WorkDetailService::OGE_RUBRIC_FILTER ] );

		self::assertSame( array( 'max_points' => 3, 'html' => '<div>рубрика</div>' ), $task['oge_rubric'] );
	}

	/** D18: деталь работы отдаёт вид контрольной + факт подтверждения — нужно JS для кнопки «Утвердить». */
	public function test_from_attempt_exposes_kind_and_approval_state(): void {
		$this->attempts->method( 'find' )->willReturn( AttemptDTO::fromArray( array(
			'id' => 9, 'assessment_id' => 1, 'student_person_id' => 10, 'group_id' => null,
			'attempt_number' => 1, 'started_at' => '2026-06-01 10:00:00', 'deadline_at' => '2026-06-01 11:00:00',
			'status' => 'graded', 'approved_at' => '2026-06-02 09:00:00', 'approved_by_user_id' => 77,
		) ) );
		$this->answers->method( 'listByAttempt' )->willReturn( array() );
		$this->assessments->method( 'get' )->willReturn( $this->assessmentFixture() );

		$detail = $this->service->forWork( 'attempt', 9 );

		self::assertSame( 'oge_computer', $detail['assessment_kind'] );
		self::assertSame( '2026-06-02 09:00:00', $detail['approved_at'] );
	}

	public function test_from_attempt_oge_rubric_null_when_module_disabled_or_not_applicable(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attemptFixture() );
		$this->answers->method( 'listByAttempt' )->willReturn( array(
			AttemptAnswerDTO::fromArray( array(
				'id' => 1, 'attempt_id' => 9, 'task_id' => 42, 'answer_text' => '{"text":"","files":[]}',
			) ),
		) );
		$this->posts->method( 'getMeta' )->willReturnCallback( function ( int $postId, string $key ) {
			return PostMetaName::TemplateType->value === $key ? 'file_answer_task' : array();
		} );
		$this->assessments->method( 'get' )->willReturn( $this->assessmentFixture() );

		$task = $this->service->forWork( 'attempt', 9 )['tasks'][0];

		self::assertNull( $task['oge_rubric'] );
	}

	/* ── attemptHistory(): «Пройти заново» — история попыток педагогу ─────── */

	private function taskAttempt( int $round, int $taskId, mixed $answer, ?bool $isCorrect, string $createdAt ): \Inc\DTO\Task\TaskAttemptDTO {
		return new \Inc\DTO\Task\TaskAttemptDTO(
			id: $round * 100 + $taskId, studentPersonId: 10, groupLessonId: 5,
			stepKey: \Inc\Enums\Course\AttemptSource::workStepKey( 3 ), taskId: $taskId,
			attemptNumber: $round, answer: $answer, isCorrect: $isCorrect,
			score: true === $isCorrect ? 1.0 : ( false === $isCorrect ? 0.0 : null ),
			maxScore: 1.0, itemFeedback: null, createdAt: $createdAt,
		);
	}

	public function test_attempt_history_null_when_submission_not_found(): void {
		$this->submissions->method( 'find' )->willReturn( null );

		self::assertNull( $this->service->attemptHistory( 7 ) );
	}

	public function test_attempt_history_empty_array_when_no_attempts_recorded(): void {
		$this->submissions->method( 'find' )->willReturn( $this->sub( null ) );
		$this->taskAttempts->method( 'listByStep' )->willReturn( array() );

		self::assertSame( array(), $this->service->attemptHistory( 7 ) );
	}

	/** Две сдачи одной работы группируются по attemptNumber в раунды, ни одна не теряется. */
	public function test_attempt_history_groups_by_round_and_marks_current(): void {
		$this->submissions->method( 'find' )->willReturn( $this->sub( null ) );
		$this->works->method( 'get' )->willReturn( $this->workWithItems( array( 42 ) ) );
		$this->posts->method( 'getMeta' )->willReturn( 'standard_task' );
		$this->posts->method( 'taskMeta' )->willReturn( array( 'task_condition' => 'Условие' ) );
		$this->taskAttempts->method( 'listByStep' )->willReturn( array(
			$this->taskAttempt( 1, 42, 'первый ответ', false, '2026-08-21 14:02:00' ),
			$this->taskAttempt( 2, 42, 'второй ответ', true, '2026-08-26 19:15:00' ),
		) );

		$history = $this->service->attemptHistory( 7 );

		self::assertCount( 2, $history );
		self::assertSame( 1, $history[0]['round'] );
		self::assertFalse( $history[0]['is_current'] );
		self::assertSame( 'incorrect', $history[0]['tasks'][0]['verdict'] );
		self::assertSame( 'первый ответ', $history[0]['tasks'][0]['answer'] );

		self::assertSame( 2, $history[1]['round'] );
		self::assertTrue( $history[1]['is_current'] );
		self::assertSame( 'correct', $history[1]['tasks'][0]['verdict'] );
		self::assertSame( 'второй ответ', $history[1]['tasks'][0]['answer'] );
	}

	/** Каждый раунд отдаёт своё затраченное время, задача — момент ответа; у старых сдач — null. */
	public function test_attempt_history_exposes_round_duration_and_answered_at(): void {
		$this->submissions->method( 'find' )->willReturn( $this->sub( null ) );
		$this->works->method( 'get' )->willReturn( $this->workWithItems( array( 42 ) ) );
		$this->posts->method( 'getMeta' )->willReturn( 'standard_task' );
		$this->posts->method( 'taskMeta' )->willReturn( array() );

		$timed = new \Inc\DTO\Task\TaskAttemptDTO(
			id: 242, studentPersonId: 10, groupLessonId: 5,
			stepKey: \Inc\Enums\Course\AttemptSource::workStepKey( 3 ), taskId: 42,
			attemptNumber: 2, answer: 'второй', isCorrect: true, score: 1.0, maxScore: 1.0,
			itemFeedback: null, createdAt: '2026-08-26 19:15:00',
			durationSec: 1500, answeredAt: '2026-08-26 19:10:00',
		);
		$this->taskAttempts->method( 'listByStep' )->willReturn( array(
			$this->taskAttempt( 1, 42, 'первый', false, '2026-08-21 14:02:00' ),
			$timed,
		) );

		$history = $this->service->attemptHistory( 7 );

		self::assertNull( $history[0]['duration_sec'] );
		self::assertNull( $history[0]['tasks'][0]['answered_at'] );
		self::assertSame( 1500, $history[1]['duration_sec'] );
		self::assertSame( '2026-08-26 19:10:00', $history[1]['tasks'][0]['answered_at'] );
	}

	/** Код-шаблоны (TaskTemplate::hasCodeField()) отдают код отдельным полем в истории. */
	public function test_attempt_history_splits_code_field_for_code_templates(): void {
		$this->submissions->method( 'find' )->willReturn( $this->sub( null ) );
		$this->works->method( 'get' )->willReturn( $this->workWithItems( array( 42 ) ) );
		$this->posts->method( 'getMeta' )->willReturn( 'code_task' );
		$this->posts->method( 'taskMeta' )->willReturn( array() );
		$this->taskAttempts->method( 'listByStep' )->willReturn( array(
			$this->taskAttempt( 1, 42, array( 'text' => 'print(1)', 'code' => "x = 1\nprint(x)" ), true, '2026-08-26 19:15:00' ),
		) );

		$task = $this->service->attemptHistory( 7 )[0]['tasks'][0];

		self::assertSame( 'print(1)', $task['answer'] );
		self::assertSame( "x = 1\nprint(x)", $task['code'] );
	}

	/**
	 * Tasks.md, п. 1: прошлый раунд рендерит тот же `taskBlock()`, что и текущий —
	 * без эталона блок «Правильный ответ» пропадал при переключении попытки.
	 */
	public function test_attempt_history_includes_correct_answer(): void {
		$this->submissions->method( 'find' )->willReturn( $this->sub( null ) );
		$this->works->method( 'get' )->willReturn( $this->workWithItems( array( 42 ) ) );
		$this->posts->method( 'getMeta' )->willReturn( 'standard_task' );
		$this->posts->method( 'taskMeta' )->willReturn( array() );
		$this->correctAnswers->method( 'resolve' )->with( 42 )->willReturn( 'Макс: 2; 3' );
		$this->taskAttempts->method( 'listByStep' )->willReturn( array(
			$this->taskAttempt( 1, 42, 'первый ответ', false, '2026-08-21 14:02:00' ),
		) );

		$task = $this->service->attemptHistory( 7 )[0]['tasks'][0];

		self::assertSame( 'Макс: 2; 3', $task['correct'] );
	}
}
