<?php

declare( strict_types=1 );

namespace Inc\DTO\Course;

readonly class GradebookEntryDTO {

	public function __construct(
		public int     $studentPersonId,
		public int     $groupId,
		public string  $sourceType,
		public int     $sourceId,
		public string  $title,
		public string  $category,
		public ?float  $score,
		public ?float  $maxScore,
		public ?string $gradedAt,
	) {}
}
