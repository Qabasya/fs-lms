<?php

declare( strict_types=1 );

namespace Inc\DTO;

readonly class PersonDTO {

	public function __construct(
		public int     $id,
		public ?int    $wpUserId,
		public string  $fullName,
		public ?string $birthDate,
		public string  $role,
		public ?string $deletedAt,
		public string  $createdAt,
		public string  $updatedAt,
	) {}

	public static function fromArray( array $row ): static {
		return new static(
			id:        (int) $row['id'],
			wpUserId:  isset( $row['wp_user_id'] ) ? (int) $row['wp_user_id'] : null,
			fullName:  (string) ( $row['full_name'] ?? '' ),
			birthDate: isset( $row['birth_date'] ) ? (string) $row['birth_date'] : null,
			role:      (string) ( $row['role'] ?? 'student' ),
			deletedAt: isset( $row['deleted_at'] ) ? (string) $row['deleted_at'] : null,
			createdAt: (string) $row['created_at'],
			updatedAt: (string) $row['updated_at'],
		);
	}

	public function toArray(): array {
		return array(
			'id'         => $this->id,
			'wp_user_id' => $this->wpUserId,
			'full_name'  => $this->fullName,
			'birth_date' => $this->birthDate,
			'role'       => $this->role,
			'deleted_at' => $this->deletedAt,
			'created_at' => $this->createdAt,
			'updated_at' => $this->updatedAt,
		);
	}
}
