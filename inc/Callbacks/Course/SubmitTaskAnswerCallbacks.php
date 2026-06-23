<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Course;

use Inc\Core\BaseController;
use Inc\DTO\Course\StepDTO;
use Inc\Enums\Course\ProgressStatus;
use Inc\Enums\Course\StepType;
use Inc\Enums\Wp\Nonce;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Wp\PostManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\TaskAttemptRepository;
use Inc\Services\Course\EffectiveStepSettingsResolver;
use Inc\Services\Course\LessonProgressService;
use Inc\Services\Task\TaskCheckerRegistry;
use Inc\Services\Template\TemplateResolver;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class SubmitTaskAnswerCallbacks
 *
 * AJAX сдачи ответа на интерактивное задание (Этап 6, Фаза C–D).
 * Записывает попытку, авто-проверяет, обновляет статус шага урока.
 * Настройки (попытки, подсказка) разрешаются через EffectiveStepSettingsResolver
 * с учётом двухуровневого переопределения на уровне группового занятия.
 *
 * @package Inc\Callbacks\Course
 */
class SubmitTaskAnswerCallbacks extends BaseController {

	use Sanitizer;

	public function __construct(
		private readonly PersonRepository             $persons,
		private readonly GroupLessonRepository        $groupLessons,
		private readonly PostManager                  $posts,
		private readonly TaskCheckerRegistry          $checkers,
		private readonly TaskAttemptRepository        $taskAttempts,
		private readonly LessonProgressService        $progress,
		private readonly TemplateResolver             $resolver,
		private readonly EffectiveStepSettingsResolver $settingsResolver,
	) {
		parent::__construct();
	}

	/**
	 * POST: group_lesson_id, step_key, answer (JSON-encoded mixed).
	 */
	public function ajaxSubmitTaskAnswer(): void {
		Nonce::SubmitTaskAnswer->verify();

		$userId = get_current_user_id();
		if ( ! $userId ) {
			$this->error( 'Требуется авторизация.' );
			return;
		}

		$groupLessonId = $this->requireInt( 'group_lesson_id' );
		$stepKey       = $this->requireKey( 'step_key' );
		$rawAnswer     = $this->sanitizeText( 'answer' );

		$person = $this->persons->findByWpUserId( $userId );
		if ( ! $person ) {
			$this->error( 'Профиль не найден.' );
			return;
		}

		$groupLesson = $this->groupLessons->find( $groupLessonId );
		if ( ! $groupLesson || ! $groupLesson->lessonId ) {
			$this->error( 'Занятие не найдено.' );
			return;
		}

		$lessonMeta = $this->posts->getMeta( $groupLesson->lessonId, PostMetaName::Meta->value );
		$steps      = StepDTO::fromList( is_array( $lessonMeta ) ? ( $lessonMeta['steps'] ?? array() ) : array() );

		$step = null;
		foreach ( $steps as $s ) {
			if ( $s->key === $stepKey ) {
				$step = $s;
				break;
			}
		}

		if ( null === $step || StepType::Task !== $step->type ) {
			$this->error( 'Шаг не найден.' );
			return;
		}

		$taskId = (int) ( $step->payload['ref'] ?? 0 );
		if ( ! $taskId ) {
			$this->error( 'Задание не привязано к шагу.' );
			return;
		}

		$task = $this->posts->get( $taskId );
		if ( ! $task ) {
			$this->error( 'Задание не найдено.' );
			return;
		}

		$template = $this->resolver->resolveEnum( $task );
		$checker  = $this->checkers->get( $template );

		if ( null === $checker ) {
			$this->error( 'Задание проверяется вручную и не поддерживает авто-сдачу.' );
			return;
		}

		$settings         = $this->settingsResolver->resolve( $step, $groupLesson, $template );
		$previousAttempts = $this->taskAttempts->listByStep( $person->id, $groupLessonId, $stepKey );
		$usedCount        = count( $previousAttempts );

		if ( $settings->maxAttempts > 0 && $usedCount >= $settings->maxAttempts ) {
			$this->error( 'Все попытки исчерпаны.' );
			return;
		}

		$taskMeta      = $this->posts->getMeta( $taskId, PostMetaName::Meta->value );
		$answer        = json_decode( $rawAnswer, true ) ?? $rawAnswer;
		$result        = $checker->check( is_array( $taskMeta ) ? $taskMeta : array(), $answer );
		$attemptNumber = $usedCount + 1;

		$this->taskAttempts->create(
			studentPersonId: $person->id,
			groupLessonId  : $groupLessonId,
			stepKey        : $stepKey,
			taskId         : $taskId,
			attemptNumber  : $attemptNumber,
			answer         : $answer,
			isCorrect      : $result->isCorrect,
			score          : $result->score,
			maxScore       : $result->maxScore,
			itemFeedback   : $result->itemFeedback,
		);

		if ( $result->isCorrect ) {
			$this->progress->markCompleted( $person->id, $groupLessonId, $stepKey );
		}

		$wrongCount = count( array_filter( $previousAttempts, static fn( $a ) => false === $a->isCorrect ) )
			+ ( $result->isCorrect ? 0 : 1 );
		$revealHint = $settings->hintAfterErrors > 0 && $wrongCount >= $settings->hintAfterErrors;
		$exhausted  = $settings->maxAttempts > 0 && $attemptNumber >= $settings->maxAttempts;

		if ( $exhausted && ! $result->isCorrect ) {
			$this->progress->markFailed( $person->id, $groupLessonId, $stepKey );
		}

		$stepStatus = match ( true ) {
			$result->isCorrect => ProgressStatus::Completed->value,
			$exhausted         => ProgressStatus::Failed->value,
			default            => ProgressStatus::Available->value,
		};

		$this->success( array(
			'is_correct'     => $result->isCorrect,
			'score'          => $result->score,
			'max_score'      => $result->maxScore,
			'item_feedback'  => $result->itemFeedback,
			'attempt_number' => $attemptNumber,
			'attempts_used'  => $attemptNumber,
			'max_attempts'   => $settings->maxAttempts,
			'reveal_hint'    => $revealHint,
			'step_status'    => $stepStatus,
		) );
	}
}
