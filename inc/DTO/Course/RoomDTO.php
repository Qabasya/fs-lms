<?php

declare( strict_types=1 );

namespace Inc\DTO\Course;

/**
 * Кабинет/аудитория (fs_lms_rooms, Эпик 9). Глобальный справочник (не привязан
 * к учебному периоду). `allowedSubjects` пусто = любой предмет.
 *
 * @package Inc\DTO\Course
 */
readonly class RoomDTO {

	/** @param string[] $allowedSubjects */
	public function __construct(
		public int    $id,
		public string $name,
		public int    $seats,
		public array  $allowedSubjects,
		public bool   $isActive,
		public string $createdAt = '',
	) {}

	public static function fromArray( array $row ): self {
		$subjects = array();
		if ( ! empty( $row['allowed_subjects'] ) ) {
			$decoded = json_decode( (string) $row['allowed_subjects'], true );
			if ( is_array( $decoded ) ) {
				$subjects = array_values( array_filter( array_map( 'strval', $decoded ) ) );
			}
		}
		return new self(
			id              : (int) $row['id'],
			name            : (string) $row['name'],
			seats           : (int) ( $row['seats'] ?? 0 ),
			allowedSubjects : $subjects,
			isActive        : (bool) ( $row['is_active'] ?? true ),
			createdAt       : (string) ( $row['created_at'] ?? '' ),
		);
	}

	/** Разрешён ли предмет в кабинете (пустой список = любой). */
	public function allowsSubject( string $subjectKey ): bool {
		return array() === $this->allowedSubjects || in_array( $subjectKey, $this->allowedSubjects, true );
	}

	/** @return array<string, mixed> */
	public function toArray(): array {
		return array(
			'id'               => $this->id,
			'name'             => $this->name,
			'seats'            => $this->seats,
			'allowed_subjects' => $this->allowedSubjects,
			'is_active'        => $this->isActive,
		);
	}
}
