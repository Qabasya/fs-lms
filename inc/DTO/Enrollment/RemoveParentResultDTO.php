<?php

declare( strict_types=1 );

namespace Inc\DTO\Enrollment;

readonly class RemoveParentResultDTO {

	public function __construct(
		public string $joinUrl,
	) {}

	public function toArray(): array {
		return array( 'join_url' => $this->joinUrl );
	}
}
