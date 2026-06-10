<?php

declare( strict_types=1 );

namespace Inc\DTO\Person;

readonly class PersonDTO {

	public function __construct(
		public int     $id,
		public ?int    $wpUserId,
		public string  $lastName,
		public string  $firstName,
		public ?string $middleName,
		public ?string $birthDate,
		public bool    $isStudent,
		public ?string $school,
		public ?string $grade,
		public ?string $expelledAt,
		public string  $createdAt,
		public string  $updatedAt,
	) {}

	public function fullName(): string {
		return trim( "{$this->lastName} {$this->firstName} " . ( $this->middleName ?? '' ) );
	}

	public static function fromArray( array $row ): static {
		return new static(
			id:         (int) $row['id'],
			wpUserId:   isset( $row['wp_user_id'] ) ? (int) $row['wp_user_id'] : null,
			lastName:   (string) ( $row['last_name']  ?? '' ),
			firstName:  (string) ( $row['first_name'] ?? '' ),
			middleName: isset( $row['middle_name'] ) && '' !== $row['middle_name'] ? (string) $row['middle_name'] : null,
			birthDate:  isset( $row['birth_date'] ) ? (string) $row['birth_date'] : null,
			isStudent:  (bool) ( $row['is_student'] ?? false ),
			school:     isset( $row['school'] ) && '' !== $row['school'] ? (string) $row['school'] : null,
			grade:      isset( $row['grade'] )  && '' !== $row['grade']  ? (string) $row['grade']  : null,
			expelledAt: isset( $row['expelled_at'] ) ? (string) $row['expelled_at'] : null,
			createdAt:  (string) $row['created_at'],
			updatedAt:  (string) $row['updated_at'],
		);
	}

	public function toArray(): array {
		return array(
			'id'          => $this->id,
			'wp_user_id'  => $this->wpUserId,
			'last_name'   => $this->lastName,
			'first_name'  => $this->firstName,
			'middle_name' => $this->middleName,
			'birth_date'  => $this->birthDate,
			'is_student'  => $this->isStudent ? 1 : 0,
			'school'      => $this->school,
			'grade'       => $this->grade,
			'expelled_at' => $this->expelledAt,
			'created_at'  => $this->createdAt,
			'updated_at'  => $this->updatedAt,
		);
	}
}
