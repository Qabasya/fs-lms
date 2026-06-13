<?php

declare( strict_types=1 );

namespace Inc\DTO\Enrollment;

readonly class ParentAssignmentResultDTO {

	public function __construct(
		public string $joinUrl,
		public string $parentName,
	) {}

	public function toArray(): array {
		return array(
			'join_url'    => $this->joinUrl,
			'parent_name' => $this->parentName,
		);
	}
}
