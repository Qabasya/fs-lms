<?php

declare(strict_types=1);

namespace Inc\DTO;

readonly class AcademicPeriodDTO {
	public function __construct(
		public string $id,
		public string $name,
		public string $start_date,
		public string $end_date,
		public bool   $is_current = false,
	) {}

	public static function fromArray( array $data ): self {
		return new self(
			id:         (string) ( $data['id'] ?? '' ),
			name:       (string) ( $data['name'] ?? '' ),
			start_date: (string) ( $data['start_date'] ?? '' ),
			end_date:   (string) ( $data['end_date'] ?? '' ),
			is_current: (bool)   ( $data['is_current'] ?? false ),
		);
	}

	public function toArray(): array {
		return array(
			'id'         => $this->id,
			'name'       => $this->name,
			'start_date' => $this->start_date,
			'end_date'   => $this->end_date,
			'is_current' => $this->is_current,
		);
	}
}
