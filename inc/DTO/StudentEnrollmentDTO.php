<?php

declare(strict_types=1);

namespace Inc\DTO;

readonly class StudentEnrollmentDTO {
	public function __construct(
		public int    $student_id,
		public string $period_id,
		public int    $class_num = 0,
		public string $group_id  = '',
	) {}

	public static function fromArray( array $data ): self {
		return new self(
			student_id: (int)    ( $data['student_id'] ?? 0 ),
			period_id:  (string) ( $data['period_id'] ?? '' ),
			class_num:  (int)    ( $data['class_num'] ?? 0 ),
			group_id:   (string) ( $data['group_id'] ?? '' ),
		);
	}

	public function toArray(): array {
		return array(
			'student_id' => $this->student_id,
			'period_id'  => $this->period_id,
			'class_num'  => $this->class_num,
			'group_id'   => $this->group_id,
		);
	}

	public function storageKey(): string {
		return "usr_{$this->student_id}_{$this->period_id}";
	}
}
