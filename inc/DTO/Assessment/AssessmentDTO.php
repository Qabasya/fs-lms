<?php

declare( strict_types=1 );

namespace Inc\DTO\Assessment;

use Inc\Enums\Assessment\ScoringPolicy;
use Inc\Services\PostTypeResolver;

readonly class AssessmentDTO {

	public function __construct(
		public int           $id,
		public string        $subjectKey,
		public string        $title,
		public array         $taskIds,
		public int           $timeLimit,
		public int           $attemptsAllowed,
		public float         $passScore,
		public bool          $shuffle,
		public ScoringPolicy $scoringPolicy,
		public string        $status,
	) {}

	public static function fromPost( \WP_Post $post, array $meta ): self {
		$policy = ScoringPolicy::tryFrom( (string) ( $meta['scoring_policy'] ?? '' ) ) ?? ScoringPolicy::Highest;

		return new self(
			id              : $post->ID,
			subjectKey      : PostTypeResolver::subjectFromAssessmentPostType( $post->post_type ),
			title           : $post->post_title,
			taskIds         : array_values( array_filter( array_map( 'intval', (array) ( $meta['task_ids'] ?? [] ) ) ) ),
			timeLimit       : (int) ( $meta['time_limit_minutes'] ?? 0 ),
			attemptsAllowed : (int) ( $meta['max_attempts'] ?? 0 ),
			passScore       : (float) ( $meta['pass_score'] ?? 0 ),
			shuffle         : (bool) ( $meta['shuffle'] ?? false ),
			scoringPolicy   : $policy,
			status          : $post->post_status,
		);
	}
}
