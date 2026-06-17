<?php

declare( strict_types=1 );

namespace Inc\DTO\Course;

readonly class GradeDTO {

	public function __construct(
		public float   $score,
		public float   $maxScore,
		public ?string $feedback = null,
		public string  $status   = 'graded',
	) {}
}
