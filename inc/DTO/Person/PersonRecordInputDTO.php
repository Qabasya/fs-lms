<?php

declare( strict_types=1 );

namespace Inc\DTO\Person;

readonly class PersonRecordInputDTO {

	public function __construct(
		public string  $lastName,
		public string  $firstName,
		public int     $isStudent,
		public string  $createdAt,
		public string  $updatedAt,
		public ?string $middleName = null,
		public ?string $birthDate  = null,
		public ?string $school     = null,
		public ?string $grade      = null,
	) {}

	public function toArray(): array {
		return array(
			'last_name'   => $this->lastName,
			'first_name'  => $this->firstName,
			'middle_name' => $this->middleName,
			'birth_date'  => $this->birthDate,
			'is_student'  => $this->isStudent,
			'school'      => $this->school,
			'grade'       => $this->grade,
			'created_at'  => $this->createdAt,
			'updated_at'  => $this->updatedAt,
		);
	}
}
