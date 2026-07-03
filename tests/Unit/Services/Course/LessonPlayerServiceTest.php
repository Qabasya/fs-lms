<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\LessonDTO;
use Inc\DTO\Course\StepDTO;
use Inc\Enums\Course\GateState;
use Inc\Enums\Course\ProgressStatus;
use Inc\Enums\Course\StepType;
use Inc\Managers\Course\LessonManager;
use Inc\Managers\Course\WorkManager;
use Inc\Managers\Wp\PostManager;
use Inc\Repositories\WPDBRepositories\TaskAttemptRepository;
use Inc\Services\Course\EffectiveStepSettingsResolver;
use Inc\Services\Course\LessonGateResolver;
use Inc\Services\Course\LessonPlayerService;
use Inc\Services\Course\LessonProgressService;
use Inc\Services\Course\SubmissionService;
use Inc\Services\Task\CorrectAnswerResolver;
use Inc\Services\Task\TaskCheckerRegistry;
use Inc\Services\Template\TemplateResolver;
use PHPUnit\Framework\TestCase;

class LessonPlayerServiceTest extends TestCase {

	private LessonManager                 $lessons;
	private LessonGateResolver            $gate;
	private LessonProgressService         $progress;
	private PostManager                   $posts;
	private TaskAttemptRepository         $taskAttempts;
	private EffectiveStepSettingsResolver $settingsResolver;
	private TemplateResolver              $templateResolver;
	private TaskCheckerRegistry           $checkerRegistry;
	private CorrectAnswerResolver         $correctAnswers;
	private WorkManager                   $works;
	private SubmissionService             $submissionService;
	private LessonPlayerService           $service;

	protected function setUp(): void {
		parent::setUp();
		$this->lessons          = $this->createMock( LessonManager::class );
		$this->gate             = $this->createMock( LessonGateResolver::class );
		$this->progress         = $this->createMock( LessonProgressService::class );
		$this->posts            = $this->createMock( PostManager::class );
		$this->taskAttempts     = $this->createMock( TaskAttemptRepository::class );
		$this->settingsResolver = $this->createMock( EffectiveStepSettingsResolver::class );
		$this->templateResolver = $this->createMock( TemplateResolver::class );
		$this->checkerRegistry  = $this->createMock( TaskCheckerRegistry::class );
		$this->correctAnswers   = $this->createMock( CorrectAnswerResolver::class );
		$this->works            = $this->createMock( WorkManager::class );
		$this->submissionService = $this->createMock( SubmissionService::class );
		$this->service          = new LessonPlayerService(
			$this->lessons,
			$this->gate,
			$this->progress,
			$this->posts,
			$this->taskAttempts,
			$this->settingsResolver,
			$this->templateResolver,
			$this->checkerRegistry,
			$this->correctAnswers,
			$this->works,
			$this->submissionService,
		);
	}

	private function makeGroupLesson( int $id = 1, int $lessonId = 10, ?string $recordingUrl = null ): GroupLessonDTO {
		return new GroupLessonDTO(
			id               : $id,
			groupId          : 5,
			lessonId         : $lessonId,
			position         : 1,
			workIdsSnapshot  : null,
			extraWorkIds     : array(),
			scheduledAt      : null,
			endsAt           : null,
			isPinned         : false,
			teacherUserId    : null,
			visibility       : 'open',
			openedAt         : null,
			homeworkDueAt    : null,
			allowLate        : true,
			recordingUrl     : $recordingUrl,
			createdByUserId  : null,
			updatedByUserId  : null,
		);
	}

	private function makeLesson( int $id, array $steps ): LessonDTO {
		return new LessonDTO(
			id        : $id,
			subjectKey: 'inf',
			topic     : 'Урок',
			steps     : $steps,
			authorId  : 1,
			status    : 'publish',
		);
	}

	private function makeVideoStep( string $key, array $payload = array() ): StepDTO {
		return new StepDTO( $key, StepType::Video, $payload );
	}

	private function stubGateAndProgress( StepDTO ...$steps ): void {
		$this->gate->method( 'resolveStep' )->willReturn( GateState::Available );
		$this->progress->method( 'getStepStatuses' )->willReturn( array() );
	}

	// ── buildView ────────────────────────────────────────────────────────────

	public function test_build_view_returns_null_when_lesson_missing(): void {
		$this->lessons->method( 'get' )->willReturn( null );

		$result = $this->service->buildView( 1, $this->makeGroupLesson() );

		self::assertNull( $result );
	}

	public function test_build_view_returns_view_structure(): void {
		$step   = $this->makeVideoStep( 's1', array( 'url' => 'https://example.com/video' ) );
		$lesson = $this->makeLesson( 10, array( $step ) );
		$this->lessons->method( 'get' )->willReturn( $lesson );
		$this->stubGateAndProgress( $step );

		$view = $this->service->buildView( 1, $this->makeGroupLesson( lessonId: 10 ) );

		self::assertNotNull( $view );
		self::assertSame( 10, $view['lesson_id'] );
		self::assertCount( 1, $view['steps'] );
	}

	// ── recording slot (T1.5.13) ─────────────────────────────────────────────

	public function test_video_slot_uses_recording_url_when_available(): void {
		$step   = $this->makeVideoStep( 's1', array(
			'url'            => 'https://fallback.com/video',
			'recording_slot' => true,
		) );
		$lesson = $this->makeLesson( 10, array( $step ) );
		$this->lessons->method( 'get' )->willReturn( $lesson );
		$this->stubGateAndProgress( $step );

		$groupLesson = $this->makeGroupLesson( lessonId: 10, recordingUrl: 'https://s3.example.com/rec.mp4' );
		$view        = $this->service->buildView( 1, $groupLesson );

		$render = $view['steps'][0]['render'];
		self::assertSame( 'https://s3.example.com/rec.mp4', $render['url'] );
		self::assertTrue( $render['recording_slot'] );
	}

	public function test_video_slot_returns_empty_url_when_no_recording(): void {
		$step   = $this->makeVideoStep( 's1', array(
			'url'            => '',
			'recording_slot' => true,
		) );
		$lesson = $this->makeLesson( 10, array( $step ) );
		$this->lessons->method( 'get' )->willReturn( $lesson );
		$this->stubGateAndProgress( $step );

		$groupLesson = $this->makeGroupLesson( lessonId: 10, recordingUrl: null );
		$view        = $this->service->buildView( 1, $groupLesson );

		$render = $view['steps'][0]['render'];
		self::assertSame( '', $render['url'] );
		self::assertTrue( $render['recording_slot'] );
	}

	public function test_non_slot_video_ignores_recording_url(): void {
		$step   = $this->makeVideoStep( 's1', array(
			'url'            => 'https://youtube.com/watch?v=abc',
			'recording_slot' => false,
		) );
		$lesson = $this->makeLesson( 10, array( $step ) );
		$this->lessons->method( 'get' )->willReturn( $lesson );
		$this->stubGateAndProgress( $step );

		$groupLesson = $this->makeGroupLesson( lessonId: 10, recordingUrl: 'https://s3.example.com/rec.mp4' );
		$view        = $this->service->buildView( 1, $groupLesson );

		$render = $view['steps'][0]['render'];
		self::assertSame( 'https://youtube.com/watch?v=abc', $render['url'] );
		self::assertFalse( $render['recording_slot'] );
	}

	public function test_video_render_includes_provider_field(): void {
		$step   = $this->makeVideoStep( 's1', array(
			'url'      => 'https://vimeo.com/123',
			'provider' => 'vimeo',
		) );
		$lesson = $this->makeLesson( 10, array( $step ) );
		$this->lessons->method( 'get' )->willReturn( $lesson );
		$this->stubGateAndProgress( $step );

		$view   = $this->service->buildView( 1, $this->makeGroupLesson( lessonId: 10 ) );
		$render = $view['steps'][0]['render'];

		self::assertSame( 'vimeo', $render['provider'] );
	}

	// ── Work-шаг (D19, T14.9): задачи работы + мета + текущая сдача ─────────

	/**
	 * Контекст work-шага: работа 55 с задачами 71, 72 (choice с эталоном в мете).
	 */
	private function arrangeWorkStep(): GroupLessonDTO {
		$step   = new StepDTO( 'w1', StepType::Work, array( 'ref' => 55 ) );
		$lesson = $this->makeLesson( 10, array( $step ) );
		$this->lessons->method( 'get' )->willReturn( $lesson );
		$this->stubGateAndProgress( $step );

		$this->works->method( 'get' )->with( 55 )->willReturn( new \Inc\DTO\Course\WorkDTO(
			id          : 55,
			subjectKey  : 'inf',
			title       : 'Работа №2 «Циклы»',
			workType    : \Inc\Enums\Course\WorkType::Practice,
			itemIds     : array( 71, 72 ),
			instructions: 'Решите все задачи.',
			authorId    : 1,
			status      : 'publish',
		) );

		$this->posts->method( 'get' )->willReturnCallback(
			static fn( int $id ) => new \WP_Post( array( 'ID' => $id, 'post_title' => "Задача {$id}" ) )
		);
		$this->posts->method( 'getMeta' )->willReturn( array(
			'task_condition' => 'Сколько итераций?',
			'task_options'   => array(
				'multiple' => false,
				'options'  => array(
					array( 'id' => 'o1', 'text' => 'Три', 'correct' => false ),
					array( 'id' => 'o2', 'text' => 'Пять', 'correct' => true ),
				),
			),
		) );
		$this->templateResolver->method( 'resolveEnum' )->willReturn( \Inc\Enums\Subject\TaskTemplate::Choice );
		$this->checkerRegistry->method( 'has' )->willReturn( true );

		return $this->makeGroupLesson( lessonId: 10 );
	}

	public function test_work_render_returns_tasks_without_answers(): void {
		$groupLesson = $this->arrangeWorkStep();
		$this->submissionService->method( 'getSubmissionsForView' )->willReturn( array() );

		$render = $this->service->buildView( 1, $groupLesson )['steps'][0]['render'];

		self::assertTrue( $render['work_found'] );
		self::assertSame( 'Работа №2 «Циклы»', $render['title'] );
		self::assertSame( 2, $render['task_count'] );
		self::assertSame( 2, $render['total_points'] );
		self::assertNull( $render['submission'] );
		self::assertCount( 2, $render['tasks'] );

		$task = $render['tasks'][0];
		self::assertSame( 71, $task['task_id'] );
		self::assertSame( 'choice', $task['widget_data']['type'] );
		// Эталонные ответы не покидают сервер: у опций только id и text.
		foreach ( $task['widget_data']['options'] as $option ) {
			self::assertSame( array( 'id', 'text' ), array_keys( $option ) );
		}
	}

	public function test_work_render_includes_current_submission(): void {
		$groupLesson = $this->arrangeWorkStep();

		$aggregate = $this->submissionRow( 1, null, '{"71":{"verdict":"correct","score":1,"maxScore":1}}', 'submitted', 1.0, 2.0 );
		$perTask   = $this->submissionRow( 2, 71, '["o2"]', 'submitted', 1.0, 1.0 );
		$this->submissionService->method( 'getSubmissionsForView' )->with( 1, 1 )
			->willReturn( array( $aggregate, $perTask ) );

		$render = $this->service->buildView( 1, $groupLesson )['steps'][0]['render'];

		self::assertNotNull( $render['submission'] );
		self::assertSame( 'submitted', $render['submission']['status'] );
		self::assertSame( 1.0, $render['submission']['score'] );
		self::assertArrayHasKey( '71', $render['submission']['verdicts'] );
		self::assertArrayHasKey( 71, $render['task_results'] );
		self::assertSame( '["o2"]', $render['task_results'][71]['answer'] );
	}

	public function test_work_render_handles_missing_work(): void {
		$step   = new StepDTO( 'w1', StepType::Work, array( 'ref' => 999 ) );
		$lesson = $this->makeLesson( 10, array( $step ) );
		$this->lessons->method( 'get' )->willReturn( $lesson );
		$this->stubGateAndProgress( $step );
		$this->works->method( 'get' )->willReturn( null );

		$render = $this->service->buildView( 1, $this->makeGroupLesson( lessonId: 10 ) )['steps'][0]['render'];

		self::assertFalse( $render['work_found'] );
	}

	private function submissionRow( int $id, ?int $taskId, string $answerText, string $status, float $score, float $maxScore ): \Inc\DTO\Course\SubmissionDTO {
		return new \Inc\DTO\Course\SubmissionDTO(
			id             : $id,
			studentPersonId: 1,
			groupLessonId  : 1,
			workId         : 55,
			workType       : \Inc\Enums\Course\WorkType::Practice,
			taskId         : $taskId,
			answerText     : $answerText,
			attachmentId   : null,
			dueAt          : null,
			status         : \Inc\Enums\Course\SubmissionStatus::from( $status ),
			score          : $score,
			maxScore       : $maxScore,
			feedback       : null,
			gradedByUserId : null,
			submittedAt    : '2026-07-01 10:00:00',
			gradedAt       : null,
			createdAt      : '2026-07-01 10:00:00',
			updatedAt      : '2026-07-01 10:00:00',
		);
	}

	// ── D20 (T14.8): эталон в render-данных task-шага после исчерпания ──────

	/**
	 * Контекст task-шага: задача 77 (choice, авто-проверка), лимит 2 попытки.
	 *
	 * @param \Inc\DTO\Task\TaskAttemptDTO[] $attempts
	 */
	private function arrangeTaskStep( array $attempts ): GroupLessonDTO {
		$step   = new StepDTO( 's1', StepType::Task, array( 'ref' => 77 ) );
		$lesson = $this->makeLesson( 10, array( $step ) );
		$this->lessons->method( 'get' )->willReturn( $lesson );
		$this->stubGateAndProgress( $step );

		$this->posts->method( 'get' )->with( 77 )->willReturn( new \WP_Post( array( 'ID' => 77 ) ) );
		$this->posts->method( 'getMeta' )->willReturn( array() );
		$this->templateResolver->method( 'resolveEnum' )->willReturn( \Inc\Enums\Subject\TaskTemplate::Choice );
		$this->checkerRegistry->method( 'has' )->willReturn( true );
		$this->settingsResolver->method( 'resolve' )
			->willReturn( new \Inc\DTO\Course\StepSettingsDTO( maxAttempts: 2 ) );
		$this->taskAttempts->method( 'listByStep' )->willReturn( $attempts );

		return $this->makeGroupLesson( lessonId: 10 );
	}

	private function attempt( int $number, bool $correct ): \Inc\DTO\Task\TaskAttemptDTO {
		return \Inc\DTO\Task\TaskAttemptDTO::fromArray( array(
			'id'                => $number,
			'student_person_id' => 1,
			'group_lesson_id'   => 1,
			'step_key'          => 's1',
			'task_id'           => 77,
			'attempt_number'    => $number,
			'is_correct'        => $correct ? 1 : 0,
			'created_at'        => '2026-01-01 00:00:00',
		) );
	}

	public function test_task_render_includes_correct_answer_when_exhausted_and_failed(): void {
		$groupLesson = $this->arrangeTaskStep( array( $this->attempt( 1, false ), $this->attempt( 2, false ) ) );
		$this->correctAnswers->method( 'resolve' )->with( 77 )->willReturn( 'Вариант Б' );
		$this->correctAnswers->method( 'choiceCorrectIds' )->with( 77 )->willReturn( array( 'o2' ) );

		$render = $this->service->buildView( 1, $groupLesson )['steps'][0]['render'];

		self::assertSame( 'Вариант Б', $render['correct_answer'] );
		self::assertSame( array( 'o2' ), $render['correct_answer_ids'] );
	}

	public function test_task_render_hides_correct_answer_while_attempts_remain(): void {
		$groupLesson = $this->arrangeTaskStep( array( $this->attempt( 1, false ) ) );
		$this->correctAnswers->expects( $this->never() )->method( 'resolve' );

		$render = $this->service->buildView( 1, $groupLesson )['steps'][0]['render'];

		self::assertArrayNotHasKey( 'correct_answer', $render );
	}

	public function test_task_render_hides_correct_answer_when_solved(): void {
		// Попытки формально исчерпаны, но среди них есть верная — шаг completed, эталон не нужен.
		$groupLesson = $this->arrangeTaskStep( array( $this->attempt( 1, false ), $this->attempt( 2, true ) ) );
		$this->correctAnswers->expects( $this->never() )->method( 'resolve' );

		$render = $this->service->buildView( 1, $groupLesson )['steps'][0]['render'];

		self::assertArrayNotHasKey( 'correct_answer', $render );
	}

	public function test_video_slot_prefers_recording_url_over_payload_url(): void {
		$step   = $this->makeVideoStep( 's1', array(
			'url'            => 'https://payload.example.com/video',
			'recording_slot' => true,
		) );
		$lesson = $this->makeLesson( 10, array( $step ) );
		$this->lessons->method( 'get' )->willReturn( $lesson );
		$this->stubGateAndProgress( $step );

		$groupLesson = $this->makeGroupLesson( lessonId: 10, recordingUrl: 'https://s3.example.com/rec.mp4' );
		$view        = $this->service->buildView( 1, $groupLesson );

		// recordingUrl must win over payload url when slot is enabled.
		self::assertSame( 'https://s3.example.com/rec.mp4', $view['steps'][0]['render']['url'] );
	}
}
