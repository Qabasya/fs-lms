<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\StepDTO;
use Inc\DTO\Course\SubmissionDTO;
use Inc\Enums\Course\ProgressStatus;
use Inc\Enums\Subject\TaskTemplate;
use Inc\Managers\Course\LessonManager;
use Inc\Managers\Course\WorkManager;
use Inc\Repositories\WPDBRepositories\TaskAttemptRepository;
use Inc\Services\Task\CorrectAnswerResolver;

/**
 * Class LessonPlayerService
 *
 * Сборка view-модели пошагового плеера урока (★, T1.5.12): упорядоченные шаги с
 * гейтом (доступ) и статусом (прогресс) для конкретного ученика в уроке программы.
 * Инлайн-шаги отдают контент для рендера; task-шаги — данные виджета без правильных ответов (Этап 6).
 * Чистый рендер контента шага (условие/виджет/видео/контрольная) — в `StepContentRenderer`,
 * общем с preview-плеером курса (Фаза 5); здесь остаётся то, что завязано на ученика/занятие
 * (гейт, прогресс, попытки, сдачи, эталон после исчерпания).
 *
 * @package Inc\Services\Course
 */
class LessonPlayerService {

	public function __construct(
		private readonly LessonManager                $lessons,
		private readonly LessonGateResolver           $gate,
		private readonly LessonProgressService        $progress,
		private readonly TaskAttemptRepository        $taskAttempts,
		private readonly EffectiveStepSettingsResolver $settingsResolver,
		private readonly CorrectAnswerResolver        $correctAnswers,
		private readonly WorkManager                  $works,
		private readonly SubmissionService            $submissionService,
		private readonly StepContentRenderer          $stepRenderer,
	) {}

	/**
	 * @return array{group_lesson_id:int, lesson_id:int, topic:string, steps:array<int, array<string,mixed>>}|null
	 */
	public function buildView( int $studentPersonId, GroupLessonDTO $groupLesson ): ?array {
		$lesson = $groupLesson->lessonId ? $this->lessons->get( $groupLesson->lessonId ) : null;
		if ( null === $lesson ) {
			return null;
		}

		$statuses = $this->progress->getStepStatuses( $studentPersonId, $groupLesson->id );

		$steps = array();
		foreach ( $lesson->steps as $step ) {
			$status = $statuses[ $step->key ] ?? ProgressStatus::Available;
			$gate   = $this->gate->resolveStep( $studentPersonId, $groupLesson, $step->key );

			$steps[] = array(
				'key'    => $step->key,
				'type'   => $step->type->value,
				'title'  => $this->stepRenderer->resolveTitle( $step ),
				'gate'   => $gate->value,
				'status' => $status->value,
				'render' => $this->renderData( $step, $groupLesson, $studentPersonId ),
			);
		}

		return array(
			'group_lesson_id' => $groupLesson->id,
			'lesson_id'       => $lesson->id,
			'topic'           => $lesson->topic,
			'steps'           => $steps,
		);
	}

	/**
	 * Данные для рендера шага по типу.
	 *
	 * @return array<string, mixed>
	 */
	private function renderData( StepDTO $step, GroupLessonDTO $groupLesson, int $studentPersonId ): array {
		return match ( $step->type->value ) {
			'text'  => array( 'content' => (string) ( $step->payload['content'] ?? '' ) ),
			'video' => $this->stepRenderer->renderVideoData( $step, $groupLesson->recordingUrl ),
			'task'       => $this->renderTaskData( $step, $groupLesson, $studentPersonId ),
			'work'       => $this->renderWorkData( $step, $groupLesson, $studentPersonId ),
			'assessment' => $this->stepRenderer->renderAssessmentData( $step ),
			default      => array( 'ref' => (int) ( $step->payload['ref'] ?? 0 ) ),
		};
	}

	/**
	 * Данные work-шага для прохождения в плеере (D19, T14.9): задачи работы
	 * (условия + виджеты БЕЗ ответов, как task-шаги), мета работы и текущая
	 * сдача (агрегат + пооответные вердикты/баллы/фидбек).
	 *
	 * @return array<string, mixed>
	 */
	private function renderWorkData( StepDTO $step, GroupLessonDTO $groupLesson, int $studentPersonId ): array {
		$workId = (int) ( $step->payload['ref'] ?? 0 );
		$empty  = array(
			'ref'        => $workId,
			'work_found' => false,
		);

		if ( ! $workId ) {
			return $empty;
		}

		$work = $this->works->get( $workId );
		if ( null === $work ) {
			return $empty;
		}

		$tasks = array();
		foreach ( $work->itemIds as $taskId ) {
			$bundle = $this->stepRenderer->taskBundle( (int) $taskId );
			if ( null === $bundle ) {
				continue;
			}

			// Ручные шаблоны в работе сдаются свободным текстом (проверит преподаватель).
			if ( ! $bundle['auto_grade'] ) {
				$bundle['widget_data'] = array( 'type' => 'text_answer' );
			}
			unset( $bundle['meta'] );

			$tasks[] = $bundle;
		}

		return array(
			'ref'             => $workId,
			'work_found'      => true,
			'title'           => $work->title,
			'work_type'       => $work->workType->value,
			'work_type_label' => $work->workType->label(),
			'instructions'    => wp_kses_post( $work->instructions ),
			'task_count'      => count( $tasks ),
			// Батч-проверка без явных весов — вес каждой задачи равен 1.
			'total_points'    => count( $tasks ),
			'tasks'           => $tasks,
		) + $this->currentSubmission( $studentPersonId, $groupLesson->id, $workId );
	}

	/**
	 * Текущая сдача работы учеником: агрегатная строка (task_id = null, в ней
	 * вердикты батч-проверки) + пооответные строки (статус/баллы/фидбек).
	 *
	 * @return array{submission: array<string,mixed>|null, task_results: array<int, array<string,mixed>>}
	 */
	private function currentSubmission( int $studentPersonId, int $groupLessonId, int $workId ): array {
		$aggregate   = null;
		$taskResults = array();

		foreach ( $this->submissionService->getSubmissionsForView( $studentPersonId, $groupLessonId ) as $submission ) {
			if ( $submission->workId !== $workId ) {
				continue;
			}

			if ( null === $submission->taskId ) {
				$verdicts  = json_decode( (string) $submission->answerText, true );
				$aggregate = array(
					'status'       => $submission->status->value,
					'status_label' => $submission->status->label(),
					'score'        => $submission->score,
					'max_score'    => $submission->maxScore,
					'submitted_at' => $submission->submittedAt,
					'verdicts'     => is_array( $verdicts ) ? $verdicts : array(),
				);
				continue;
			}

			$taskResults[ $submission->taskId ] = $this->taskResult( $submission );
		}

		return array(
			'submission'   => $aggregate,
			'task_results' => $taskResults,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function taskResult( SubmissionDTO $submission ): array {
		return array(
			'status'    => $submission->status->value,
			'answer'    => $submission->answerText,
			'score'     => $submission->score,
			'max_score' => $submission->maxScore,
			'feedback'  => null !== $submission->feedback ? wp_kses_post( $submission->feedback ) : null,
		);
	}

	/**
	 * Данные task-шага: условие, виджет (без ответов), подсказка, настройки, прогресс.
	 *
	 * @return array<string, mixed>
	 */
	private function renderTaskData( StepDTO $step, GroupLessonDTO $groupLesson, int $studentPersonId ): array {
		$empty = array(
			'auto_grade'     => false,
			'template'       => '',
			'condition_html' => '',
			'widget_data'    => array(),
			'hint_html'      => '',
			'settings'       => array(),
			'attempts_used'  => 0,
			'reveal_hint'    => false,
		);

		$taskId = (int) ( $step->payload['ref'] ?? 0 );
		if ( ! $taskId ) {
			return $empty;
		}

		$bundle = $this->stepRenderer->taskBundle( $taskId );
		if ( null === $bundle ) {
			return $empty;
		}

		$template  = TaskTemplate::from( $bundle['template'] );
		$autoGrade = $bundle['auto_grade'];
		$meta      = $bundle['meta'];

		$settings   = $this->settingsResolver->resolve( $step, $groupLesson, $template );
		// Шафл (если включён настройками шага) не зашит в bundle — считаем виджет заново
		// с нужным shuffle, а не берём bundle['widget_data'] с shuffle=false по умолчанию.
		$widgetData = $autoGrade ? $this->stepRenderer->buildWidgetData( $meta, $template, $settings->shuffle ) : array();
		$attempts   = $this->taskAttempts->listByStep( $studentPersonId, $groupLesson->id, $step->key );
		$usedCount  = count( $attempts );
		$wrongCount = count( array_filter( $attempts, static fn( $a ) => false === $a->isCorrect ) );
		$hintHtml   = wp_kses_post( (string) ( $meta['task_hint'] ?? '' ) );
		$revealHint = '' !== $hintHtml && ( $settings->hintAfterErrors === 0 || $wrongCount >= $settings->hintAfterErrors );

		$data = array(
			'auto_grade'     => $autoGrade,
			'template'       => $bundle['template'],
			'condition_html' => $bundle['condition_html'],
			'widget_data'    => $widgetData,
			'hint_html'      => $hintHtml,
			'settings'       => $settings->toArray(),
			'attempts_used'  => $usedCount,
			'reveal_hint'    => $revealHint,
		);

		// D20 (T14.8): шаг провален с исчерпанием попыток — эталон виден и после
		// перезагрузки страницы (в submit-ответе его отдаёт SubmitTaskAnswerCallbacks).
		$exhausted  = $settings->maxAttempts > 0 && $usedCount >= $settings->maxAttempts;
		$hasCorrect = array() !== array_filter( $attempts, static fn( $a ) => true === $a->isCorrect );
		if ( $autoGrade && $exhausted && ! $hasCorrect ) {
			$data['correct_answer'] = $this->correctAnswers->resolve( $taskId );

			$correctIds = $this->correctAnswers->choiceCorrectIds( $taskId );
			if ( array() !== $correctIds ) {
				$data['correct_answer_ids'] = $correctIds;
			}
		}

		return $data;
	}
}
