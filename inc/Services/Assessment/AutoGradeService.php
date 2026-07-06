<?php

declare( strict_types=1 );

namespace Inc\Services\Assessment;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Assessment\AttemptDTO;
use Inc\DTO\Log\Events\LearningEvent;
use Inc\Enums\Assessment\AttemptStatus;
use Inc\Enums\Log\LogEvent;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Managers\Wp\PostManager;
use Inc\Repositories\WPDBRepositories\AssessmentAnswerRepository;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Services\Task\TaskCheckerRegistry;
use Inc\Services\Template\TemplateResolver;

class AutoGradeService {

	public function __construct(
		private readonly AssessmentAttemptRepository $attempts,
		private readonly AssessmentAnswerRepository  $answers,
		private readonly PostManager                 $posts,
		private readonly TemplateResolver            $resolver,
		private readonly TaskCheckerRegistry         $checkers,
		private readonly LogEventDispatcherInterface $dispatcher,
		private readonly AssessmentManager           $assessments,
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
	 * Оценивает попытку по ПОЛНОМУ набору заданий контрольной (а не только по
	 * заполненным ответам): максимум складывается из всех заданий работы, иначе
	 * при пустых/частичных ответах итог был бы «0 / 0» вместо «0 / N». Для каждого
	 * задания берётся сохранённый ответ (если есть) либо пустой; авто-задания
	 * проверяются чекером, ручные — получают max_score (критерии/1) и уходят на
	 * проверку. После итерации обновляет статус попытки и диспатчит событие.
	 */
	public function gradeAttempt( AttemptDTO $attempt ): AttemptDTO {
		$assessment = $this->assessments->get( $attempt->assessmentId );
		// Фолбэк: если работа не найдена — старое поведение (по заполненным ответам).
		$taskIds = null !== $assessment
			? $assessment->taskIds
			: array_map( static fn( $a ) => $a->taskId, $this->answers->listByAttempt( $attempt->id ) );

		// Сохранённые ответы по task_id — чтобы сопоставить с полным списком заданий.
		$answersByTask = array();
		foreach ( $this->answers->listByAttempt( $attempt->id ) as $a ) {
			$answersByTask[ $a->taskId ] = $a;
		}

		$totalScore = 0.0;
		$totalMax   = 0.0;
		$hasManual  = false;

		foreach ( $taskIds as $taskId ) {
			$taskId = (int) $taskId;
			$post   = $this->posts->get( $taskId );
			if ( ! $post ) {
				// Задание удалено из банка — не учитываем в максимуме.
				continue;
			}

			$template   = $this->resolver->resolveEnum( $post );
			$checker    = $this->checkers->get( $template );
			$meta       = $this->posts->getMeta( $post->ID, PostMetaName::Meta->value );
			$metaArr    = is_array( $meta ) ? $meta : array();
			$answerText = $answersByTask[ $taskId ]->answerText ?? null;

			if ( null === $checker ) {
				$hasManual = true;
				// Эпик 13 (D17): если у задачи заданы критерии — начальный max_score
				// сразу равен их сумме (сырых баллов), а не заглушке «1».
				$criteriaDefs = is_array( $metaArr['task_criteria']['criteria'] ?? null )
					? $metaArr['task_criteria']['criteria']
					: array();
				$defaultMax   = ! empty( $criteriaDefs )
					? array_sum( array_map( static fn( $d ) => (float) ( $d['max_points'] ?? 0 ), $criteriaDefs ) )
					: 1.0;
				$this->answers->upsert( $attempt->id, $taskId, array( 'max_score' => $defaultMax ) );
				$totalMax += $defaultMax;
				continue;
			}

			$result = $checker->check( $metaArr, (string) ( $answerText ?? '' ) );

			$this->answers->upsert( $attempt->id, $taskId, array(
				'is_correct' => $result->isCorrect ? 1 : 0,
				'score'      => $result->score,
				'max_score'  => $result->maxScore,
			) );

			$totalScore += $result->score;
			$totalMax   += $result->maxScore;
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
