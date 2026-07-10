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
use Inc\Services\Course\BatchCheckService;
use Inc\Services\Template\TemplateRegistry;
use Inc\Services\Template\TemplateResolver;

class AutoGradeService {

	public function __construct(
		private readonly AssessmentAttemptRepository $attempts,
		private readonly AssessmentAnswerRepository  $answers,
		private readonly PostManager                 $posts,
		private readonly TemplateResolver            $resolver,
		private readonly LogEventDispatcherInterface $dispatcher,
		private readonly AssessmentManager           $assessments,
		private readonly BatchCheckService           $batchCheck,
		private readonly TemplateRegistry            $templates,
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
		// Фолбэк: если работа не найдена — оцениваем по заполненным ответам.
		$taskIds = null !== $assessment
			? $assessment->taskIds
			: array_map( static fn( $a ) => (int) $a->taskId, $this->answers->listByAttempt( $attempt->id ) );

		// D16.1: контрольная (Control) — бинарный балл «верно/неверно»: каждое
		// задание весит ровно 1 (max = 1). ЕГЭ/КЕГЭ — взвешенный балл + разворот
		// составных заданий (ThreeInOne → 19/20/21) через BatchCheckService.
		$binary     = null !== $assessment && $assessment->kind->binaryScoring();
		$kind       = $assessment?->kind;
		$taskPoints = $assessment?->taskPoints ?? array();

		// Сохранённые ответы по task_id (сырой текст) — для сопоставления с полным списком.
		$rawByTask = array();
		foreach ( $this->answers->listByAttempt( $attempt->id ) as $a ) {
			$rawByTask[ (int) $a->taskId ] = $a->answerText;
		}

		// Строим вход BatchCheckService: строка для обычных задач, массив {19,20,21}
		// (json_decode) для составных (Triple) — их ответ хранится JSON на родительском task_id.
		$taskAnswers  = array();
		$existingTasks = array();
		foreach ( $taskIds as $taskId ) {
			$taskId = (int) $taskId;
			$post   = $this->posts->get( $taskId );
			if ( ! $post ) {
				continue; // Задание удалено из банка — не учитываем в максимуме.
			}
			$existingTasks[ $taskId ] = true;
			$raw = $rawByTask[ $taskId ] ?? '';

			if ( $this->isComposite( $post, $kind ) ) {
				$decoded              = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;
				$taskAnswers[ $taskId ] = is_array( $decoded ) ? $decoded : array();
			} else {
				$taskAnswers[ $taskId ] = (string) ( $raw ?? '' );
			}
		}

		$batch = $this->batchCheck->check( $taskAnswers, $taskPoints, $kind );

		// Свод составных под-ключей ("taskId:19") обратно в одну строку на родительский
		// task_id: таблица assessment_answers хранит один ряд на task_id (без под-ключей).
		$agg = array();
		foreach ( $batch->perTask as $key => $r ) {
			$parent = (int) ( is_string( $key ) && str_contains( $key, ':' ) ? strstr( $key, ':', true ) : $key );
			if ( ! isset( $agg[ $parent ] ) ) {
				$agg[ $parent ] = array( 'score' => 0.0, 'max' => 0.0, 'correct' => true, 'pending' => false );
			}
			$agg[ $parent ]['score'] += (float) $r['score'];
			$agg[ $parent ]['max']   += (float) $r['maxScore'];
			if ( 'pending' === $r['verdict'] ) {
				$agg[ $parent ]['pending'] = true;
			}
			if ( 'correct' !== $r['verdict'] ) {
				$agg[ $parent ]['correct'] = false;
			}
		}

		$totalScore = 0.0;
		$totalMax   = 0.0;
		$hasManual  = false;

		foreach ( $agg as $taskId => $a ) {
			if ( ! isset( $existingTasks[ $taskId ] ) ) {
				continue;
			}

			if ( $a['pending'] ) {
				// Ручное задание (нет чекера) — уходит на проверку. D16.1: контрольная —
				// max = 1; ЕГЭ — сумма критериев (Эпик 13/D17), иначе вес слота.
				$hasManual = true;
				$max       = $binary ? 1.0 : $this->manualMax( $taskId, $a['max'] );
				$this->answers->upsert( $attempt->id, $taskId, array( 'max_score' => $max ) );
				$totalMax += $max;
				continue;
			}

			$isCorrect = $a['correct'];
			// D16.1: бинарный балл — верно→1, иначе→0, max = 1.
			$score = $binary ? ( $isCorrect ? 1.0 : 0.0 ) : $a['score'];
			$max   = $binary ? 1.0 : $a['max'];

			$this->answers->upsert( $attempt->id, $taskId, array(
				'is_correct' => $isCorrect ? 1 : 0,
				'score'      => $score,
				'max_score'  => $max,
			) );

			$totalScore += $score;
			$totalMax   += $max;
		}

		return $this->persistTotals( $attempt, $totalScore, $totalMax, $hasManual );
	}

	/** Составное ли задание (ThreeInOne) в режиме ЕГЭ — разворачивается на под-пункты. */
	private function isComposite( \WP_Post $post, ?\Inc\Enums\Assessment\AssessmentKind $kind ): bool {
		if ( null === $kind || ! $kind->expandsComposites() ) {
			return false;
		}
		$templateObj = $this->templates->get( $this->resolver->resolveId( $post ) );
		return null !== $templateObj && ! empty( $templateObj->expandsForExam() );
	}

	/** Начальный max ручного задания (ЕГЭ): сумма критериев (Эпик 13/D17), иначе вес из BatchCheck. */
	private function manualMax( int $taskId, float $weightFromBatch ): float {
		$meta         = $this->posts->getMeta( $taskId, PostMetaName::Meta->value );
		$metaArr      = is_array( $meta ) ? $meta : array();
		$criteriaDefs = is_array( $metaArr['task_criteria']['criteria'] ?? null )
			? $metaArr['task_criteria']['criteria']
			: array();
		if ( ! empty( $criteriaDefs ) ) {
			return array_sum( array_map( static fn( $d ) => (float) ( $d['max_points'] ?? 0 ), $criteriaDefs ) );
		}
		return $weightFromBatch > 0 ? $weightFromBatch : 1.0;
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
