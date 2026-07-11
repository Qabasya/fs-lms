<?php

declare( strict_types=1 );

namespace Inc\DTO\Assessment;

use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Assessment\ScoringPolicy;
use Inc\Services\Subject\PostTypeResolver;

readonly class AssessmentDTO {

	public function __construct(
		public int            $id,
		public string         $subjectKey,
		public string         $title,
		public array          $taskIds,
		public int            $timeLimit,
		public int            $attemptsAllowed,
		public float          $passScore,
		public ScoringPolicy  $scoringPolicy,
		public string         $status,
		public AssessmentKind $kind,
		/** @var array<int, float> task_id => points (empty = all tasks weight 1) */
		public array          $taskPoints,
		/** @var array<int, int> primary_score => secondary_score */
		public array          $scoreMap,
		/** @var array<int, string> task_id => номер задания (задача 8: fallback для банковских задач без таксономии) */
		public array          $taskNumbers = [],
		/** Per-work WYSIWYG-описание для интро-шага (D16.4); пусто → дефолты AssessmentIntroConfig. */
		public string         $introHtml = '',
	) {}

	public static function fromPost( \WP_Post $post, array $meta ): self {
		$policy = ScoringPolicy::tryFrom( (string) ( $meta['scoring_policy'] ?? '' ) ) ?? ScoringPolicy::Highest;
		$kind   = AssessmentKind::fromValueOrDefault( (string) ( $meta['kind'] ?? '' ) );

		$taskPoints = [];
		if ( ! empty( $meta['task_points'] ) && is_array( $meta['task_points'] ) ) {
			foreach ( $meta['task_points'] as $taskId => $points ) {
				$taskPoints[ (int) $taskId ] = (float) $points;
			}
		}

		$rawScoreMap = $meta['score_map'] ?? [];
		if ( is_string( $rawScoreMap ) && $rawScoreMap !== '' ) {
			$decoded     = json_decode( $rawScoreMap, true );
			$rawScoreMap = is_array( $decoded ) ? $decoded : [];
		}
		$scoreMap = [];
		if ( is_array( $rawScoreMap ) ) {
			foreach ( $rawScoreMap as $primary => $secondary ) {
				$scoreMap[ (int) $primary ] = (int) $secondary;
			}
		}

		$taskNumbers = [];
		if ( ! empty( $meta['task_numbers'] ) && is_array( $meta['task_numbers'] ) ) {
			foreach ( $meta['task_numbers'] as $taskId => $number ) {
				$num = trim( (string) $number );
				if ( (int) $taskId > 0 && '' !== $num ) {
					$taskNumbers[ (int) $taskId ] = $num;
				}
			}
		}

		return new self(
			id              : $post->ID,
			subjectKey      : PostTypeResolver::subjectFromAssessmentPostType( $post->post_type ),
			title           : $post->post_title,
			taskIds         : array_values( array_filter( array_map( 'intval', (array) ( $meta['task_ids'] ?? [] ) ) ) ),
			timeLimit       : (int) ( $meta['time_limit_minutes'] ?? 0 ),
			attemptsAllowed : (int) ( $meta['max_attempts'] ?? 0 ),
			passScore       : (float) ( $meta['pass_score'] ?? 0 ),
			scoringPolicy   : $policy,
			status          : $post->post_status,
			kind            : $kind,
			taskPoints      : $taskPoints,
			scoreMap        : $scoreMap,
			taskNumbers     : $taskNumbers,
			introHtml       : is_string( $meta['intro_html'] ?? null ) ? $meta['intro_html'] : '',
		);
	}

	/**
	 * Максимальный первичный балл с учётом весов заданий.
	 * Для Control (вес = 1 на задание) = count(taskIds).
	 */
	public function maxPrimary(): float {
		if ( ! $this->kind->usesWeightedScore() || empty( $this->taskPoints ) ) {
			return (float) count( $this->taskIds );
		}

		return array_sum( $this->taskPoints );
	}
}
