<?php

declare( strict_types=1 );

namespace Inc\DTO\Enrollment;

readonly class RestoreResultDTO {

	public function __construct(
		public int     $appId,
		public string  $joinUrl,
		public ?string $parentName = null,
	) {}

	public function toArray(): array {
		$data = array(
			'id'       => $this->appId,
			'join_url' => $this->joinUrl,
		);

		if ( null !== $this->parentName ) {
			$data['parent_name'] = $this->parentName;
		}

		return $data;
	}
}
