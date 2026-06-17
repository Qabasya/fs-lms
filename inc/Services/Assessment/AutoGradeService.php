<?php

declare( strict_types=1 );

namespace Inc\Services\Assessment;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Assessment\AttemptDTO;
use Inc\DTO\Log\Events\LearningEvent;
use Inc\Enums\AttemptStatus;
use Inc\Enums\LogEvent;
use Inc\Enums\PostMetaName;
use Inc\Enums\TaskTemplate;
use Inc\Managers\PostManager;
use Inc\Repositories\WPDBRepositories\AssessmentAnswerRepository;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Services\Template\TemplateResolver;

class AutoGradeService {

	/** Шаблоны, которые поддерживают авто-проверку по полю task_answer. */
	private const AUTO_GRADE_TEMPLATES = [
		TaskTemplate::Standard,
		TaskTemplate::Triple,
		TaskTemplate::Common,
	];

	public function __construct(
		private readonly AssessmentAttemptRepository $attempts,
		private readonly AssessmentAnswerRepository  $answers,
		private readonly PostManager                 $posts,
		private readonly TemplateResolver            $templateResolver,
		private readonly LogEventDispatcherInterface $dispatcher,
	) {}

	/**
	 * Пересчитывает итоговый балл и статус попытки на основе текущих ответов.
	 * Вызывается после ручной оценки отдельного ответа преподавателем.
	 */
	public function finalize( AttemptDTO $attempt ): AttemptDTO {
		$answerList = $this->answers->listByAttempt( $attempt->id );
		$totalScore = 0.0;
		$totalMax   = 0.0;
		$hasManual  = false;

		foreach ( $answerList as $answer ) {
			if ( null === $answer->isCorrect ) {
				$hasManual = true;
			}
			$totalScore += $answer->score ?? 0.0;
			$totalMax   += $answer->maxScore ?? 0.0;
		}

		return $this->persistTotals( $attempt, $totalScore, $totalMax, $hasManual );
	}

	/**
	 * Итерирует ответы попытки, авто-оценивает там, где возможно.
	 * После итерации обновляет статус попытки и диспатчит событие.
	 */
	public function gradeAttempt( AttemptDTO $attempt ): AttemptDTO {
		$answerList  = $this->answers->listByAttempt( $attempt->id );
		$totalScore  = 0.0;
		$totalMax    = 0.0;
		$hasManual   = false;

		foreach ( $answerList as $answer ) {
			$post = $this->posts->get( $answer->taskId );
			if ( ! $post ) {
				$hasManual = true;
				continue;
			}

			$template    = $this->templateResolver->resolveEnum( $post );
			$canAutoGrade = in_array( $template, self::AUTO_GRADE_TEMPLATES, true );

			if ( ! $canAutoGrade ) {
				$hasManual = true;
				$this->answers->upsert( $attempt->id, $answer->taskId, [ 'max_score' => 1 ] );
				$totalMax += 1.0;
				continue;
			}

			$meta          = $this->posts->getMeta( $post->ID, PostMetaName::Meta->value );
			$correctAnswer = is_array( $meta ) ? trim( (string) ( $meta['task_answer'] ?? '' ) ) : '';
			$studentAnswer = trim( (string) $answer->answerText );

			$isCorrect = $correctAnswer !== '' && strtolower( $studentAnswer ) === strtolower( $correctAnswer );
			$score     = $isCorrect ? 1.0 : 0.0;

			$this->answers->upsert( $attempt->id, $answer->taskId, [
				'is_correct' => $isCorrect ? 1 : 0,
				'score'      => $score,
				'max_score'  => 1,
			] );

			$totalScore += $score;
			$totalMax   += 1.0;
		}

		return $this->persistTotals( $attempt, $totalScore, $totalMax, $hasManual );
	}

	/**
	 * Сохраняет итоговый балл/статус попытки и диспатчит AttemptGraded, если попытка
	 * полностью авто-оценена. Общий хвост finalize() и gradeAttempt().
	 */
	private function persistTotals( AttemptDTO $attempt, float $totalScore, float $totalMax, bool $hasManual ): AttemptDTO {
		$newStatus = $hasManual ? AttemptStatus::Submitted : AttemptStatus::Graded;

		$this->attempts->update( $attempt->id, [
			'status'      => $newStatus->value,
			'total_score' => $totalScore,
			'max_score'   => $totalMax,
		] );

		if ( $newStatus === AttemptStatus::Graded ) {
			$this->dispatcher->dispatch(
				LogEvent::AttemptGraded,
				new LearningEvent(
					event      : LogEvent::AttemptGraded,
					actorUserId: $attempt->studentPersonId,
					groupId    : $attempt->groupId,
					entityType : 'attempt',
					entityId   : (string) $attempt->id,
					isPublic   : false,
				)
			);
		}

		$updated = $this->attempts->find( $attempt->id );
		assert( $updated !== null );
		return $updated;
	}
}
