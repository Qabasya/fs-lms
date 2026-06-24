<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\DTO\Course\BatchCheckResultDTO;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Wp\PostManager;
use Inc\Services\Task\TaskCheckerRegistry;
use Inc\Services\Template\TemplateRegistry;
use Inc\Services\Template\TemplateResolver;

/**
 * Class BatchCheckService
 *
 * Пакетная авто-проверка набора ответов (работа / экзамен).
 * Эталонные ответы не покидают сервер — только вердикт и баллы.
 *
 * @package Inc\Services\Course
 */
class BatchCheckService {

	public function __construct(
		private readonly PostManager         $posts,
		private readonly TemplateResolver    $resolver,
		private readonly TemplateRegistry    $templates,
		private readonly TaskCheckerRegistry $checkers,
	) {}

	/**
	 * Проверяет набор ответов ученика.
	 *
	 * @param array<int, mixed>      $taskAnswers      task_id => ответ ученика
	 * @param array<int|string, float> $taskPoints     task_id (или "taskId:key") => баллов (пусто → вес 1)
	 * @param AssessmentKind|null    $kind             Тип экзамена; если передан и expandsComposites() — разворачивает составные шаблоны.
	 */
	public function check( array $taskAnswers, array $taskPoints = [], ?AssessmentKind $kind = null ): BatchCheckResultDTO {
		$perTask          = [];
		$correctCount     = 0;
		$totalCount       = 0;
		$weightedScore    = 0.0;
		$maxWeightedScore = 0.0;
		$hasManual        = false;

		$expandComposites = $kind && $kind->expandsComposites();

		foreach ( $taskAnswers as $taskId => $answer ) {
			$taskId = (int) $taskId;

			$post = $this->posts->get( $taskId );
			if ( ! $post ) {
				$hasManual = true;
				$totalCount++;
				$maxWeightedScore += 1.0;
				$perTask[ $taskId ] = [ 'verdict' => 'pending', 'score' => 0.0, 'maxScore' => 1.0 ];
				continue;
			}

			$templateId  = $this->resolver->resolveId( $post );
			$templateObj = $this->templates->get( $templateId );
			$template    = $this->resolver->resolveEnum( $post );
			$meta        = $this->posts->getMeta( $post->ID, PostMetaName::Meta->value );
			$metaArr     = is_array( $meta ) ? $meta : [];

			// Разворот составного шаблона (ThreeInOne → 19 / 20 / 21) в режиме ЕГЭ.
			$subItems = ( $expandComposites && null !== $templateObj ) ? $templateObj->expandsForExam() : [];

			if ( ! empty( $subItems ) ) {
				$studentAnswers = is_array( $answer ) ? $answer : [];
				foreach ( $subItems as $sub ) {
					$subKey       = "{$taskId}:{$sub['key']}";
					$pointsKey    = $subKey;
					$subWeight    = isset( $taskPoints[ $pointsKey ] ) ? (float) $taskPoints[ $pointsKey ] : 1.0;
					$subAnswer    = (string) ( $studentAnswers[ $sub['key'] ] ?? '' );
					$correctAnswer = (string) ( $metaArr[ $sub['answer_field'] ] ?? '' );

					$totalCount++;
					$maxWeightedScore += $subWeight;

					$isCorrect = '' !== $correctAnswer && strtolower( trim( $subAnswer ) ) === strtolower( trim( $correctAnswer ) );
					$earned    = $isCorrect ? $subWeight : 0.0;

					if ( $isCorrect ) {
						$correctCount++;
					}
					$weightedScore += $earned;

					$perTask[ $subKey ] = [
						'verdict'  => $isCorrect ? 'correct' : 'incorrect',
						'score'    => $earned,
						'maxScore' => $subWeight,
					];
				}
				continue;
			}

			// Обычная задача — через TaskCheckerRegistry.
			$weight = isset( $taskPoints[ $taskId ] ) ? (float) $taskPoints[ $taskId ] : 1.0;
			$totalCount++;
			$maxWeightedScore += $weight;

			$checker = $this->checkers->get( $template );
			if ( null === $checker ) {
				$hasManual = true;
				$perTask[ $taskId ] = [ 'verdict' => 'pending', 'score' => 0.0, 'maxScore' => $weight ];
				continue;
			}

			$result = $checker->check( $metaArr, $answer );
			$earned = $result->isCorrect ? $weight : round( $result->score / max( $result->maxScore, 1.0 ) * $weight, 2 );

			if ( $result->isCorrect ) {
				$correctCount++;
			}
			$weightedScore += $earned;

			$perTask[ $taskId ] = [
				'verdict'  => $result->isCorrect ? 'correct' : 'incorrect',
				'score'    => $earned,
				'maxScore' => $weight,
			];
		}

		return new BatchCheckResultDTO(
			perTask          : $perTask,
			correctCount     : $correctCount,
			totalCount       : $totalCount,
			weightedScore    : $weightedScore,
			maxWeightedScore : $maxWeightedScore,
			hasManual        : $hasManual,
		);
	}
}
