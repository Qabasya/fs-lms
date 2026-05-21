<?php

declare(strict_types=1);

namespace Inc\DTO;

readonly class StudentGroupDTO {
	public function __construct(
		public string $id,
		public string $name,
		public string $period_id,
		public string $subject_key = '',
	) {}

	public static function fromArray( array $data ): self {
		return new self(
			id:          (string) ( $data['id'] ?? '' ),
			name:        (string) ( $data['name'] ?? '' ),
			period_id:   (string) ( $data['period_id'] ?? '' ),
			subject_key: (string) ( $data['subject_key'] ?? '' ),
		);
	}

	public function toArray(): array {
		return array(
			'id'          => $this->id,
			'name'        => $this->name,
			'period_id'   => $this->period_id,
			'subject_key' => $this->subject_key,
		);
	}
}
