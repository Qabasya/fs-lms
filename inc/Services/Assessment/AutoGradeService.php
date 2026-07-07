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
		$assessment = $this->assessments->get( $attempt->assessmentId );
		// D16.1: контрольная — бинарный балл, max каждого задания = 1 (иначе после
		// ручной оценки развёрнутого ответа max «поехал» бы с логики критериев).
		$binary = null !== $assessment && $assessment->kind->binaryScoring();

		$answerList = $this->answers->listByAttempt( $attempt->id );
		$totalScore = 0.0;
		$totalMax   = 0.0;
		$hasManual  = false;

		foreach ( $answerList as $answer ) {
			if ( null === $answer->isCorrect ) {
				$hasManual = true;
			}
			$totalScore += $binary
				? ( ( $answer->score ?? 0.0 ) > 0.0 ? 1.0 : 0.0 )
				: ( $answer->score ?? 0.0 );
			$totalMax   += $binary ? 1.0 : ( $answer->maxScore ?? 0.0 );
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

		// D16.1: контрольная (Control) — бинарный балл «верно/неверно»: каждое
		// задание весит ровно 1 (max = 1), частичный балл чекера и критерии
		// ручного задания игнорируются. ЕГЭ/КЕГЭ — прежнее взвешенное поведение.
		$binary = null !== $assessment && $assessment->kind->binaryScoring();

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
				// D16.1: контрольная — max ручного задания = 1 (0/1 при ручной проверке),
				// критерии игнорируются. Иначе (ЕГЭ, Эпик 13/D17) — начальный max_score
				// равен сумме критериев (сырых баллов), либо заглушке «1».
				$criteriaDefs = is_array( $metaArr['task_criteria']['criteria'] ?? null )
					? $metaArr['task_criteria']['criteria']
					: array();
				$defaultMax   = ( ! $binary && ! empty( $criteriaDefs ) )
					? array_sum( array_map( static fn( $d ) => (float) ( $d['max_points'] ?? 0 ), $criteriaDefs ) )
					: 1.0;
				$this->answers->upsert( $attempt->id, $taskId, array( 'max_score' => $defaultMax ) );
				$totalMax += $defaultMax;
				continue;
			}

			$result = $checker->check( $metaArr, (string) ( $answerText ?? '' ) );

			// D16.1: бинарный балл — верно→1, иначе→0, max = 1 (игнорируем
			// CheckResult::score/maxScore и частичный балл композитов).
			$score = $binary ? ( $result->isCorrect ? 1.0 : 0.0 ) : $result->score;
			$max   = $binary ? 1.0 : $result->maxScore;

			$this->answers->upsert( $attempt->id, $taskId, array(
				'is_correct' => $result->isCorrect ? 1 : 0,
				'score'      => $score,
				'max_score'  => $max,
			) );

			$totalScore += $score;
			$totalMax   += $max;
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
