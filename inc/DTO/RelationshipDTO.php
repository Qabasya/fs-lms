<?php

declare( strict_types=1 );

namespace Inc\DTO;

/**
 * Class RelationshipDTO
 *
 * Row-DTO строки таблицы fs_lms_relationships.
 *
 * @package Inc\DTO
 *
 * ### Основные обязанности:
 *
 * 1. **Хранение связи опекун-ученик** — представляет запись из таблицы relationships.
 * 2. **Преобразование массив <-> DTO** — методы fromArray() и toArray().
 *
 * ### Архитектурная роль:
 *
 * Используется в RelationshipRepository для передачи данных о том,
 * какой опекун (родитель) закреплён за каким учеником и на какой период.
 *
 * ### Примечания:
 *
 * - valid_from — дата начала действия связи
 * - valid_to = null означает бессрочную связь (действует до отмены)
 * - relation_type — тип родства (mother, father, guardian, grandparent, other)
 * - Связи имеют временную размерность для поддержки ситуаций,
 *   когда опекунство меняется или истекает (например, при достижении 18 лет)
 */
readonly class RelationshipDTO {

	/**
	 * Конструктор DTO.
	 *
	 * @param int         $id                ID записи связи
	 * @param int         $guardianPersonId  ID опекуна (из persons)
	 * @param int         $studentPersonId   ID ученика (из persons)
	 * @param string      $relationType      Тип родства (mother, father, guardian)
	 * @param string      $validFrom         Дата начала действия связи (Y-m-d)
	 * @param string|null $validTo           Дата окончания действия связи (Y-m-d) или NULL (бессрочно)
	 * @param string      $createdAt         Дата создания записи
	 */
	public function __construct(
		public int     $id,
		public int     $guardianPersonId,
		public int     $studentPersonId,
		public string  $relationType,
		public string  $validFrom,
		public ?string $validTo,
		public string  $createdAt,
	) {}

	/**
	 * Создаёт DTO из массива данных (например, из результата SQL-запроса).
	 *
	 * @param array<string, mixed> $row Ассоциативный массив с полями таблицы
	 *
	 * @return static
	 */
	public static function fromArray( array $row ): static {
		return new static(
			id:               (int) $row['id'],
			guardianPersonId: (int) $row['guardian_person_id'],
			studentPersonId:  (int) $row['student_person_id'],
			relationType:     (string) $row['relation_type'],
			validFrom:        (string) $row['valid_from'],
			validTo:          isset( $row['valid_to'] ) ? (string) $row['valid_to'] : null,
			createdAt:        (string) $row['created_at'],
		);
	}

	/**
	 * Преобразует DTO в массив для вставки/обновления в БД.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'id'                 => $this->id,
			'guardian_person_id' => $this->guardianPersonId,
			'student_person_id'  => $this->studentPersonId,
			'relation_type'      => $this->relationType,
			'valid_from'         => $this->validFrom,
			'valid_to'           => $this->validTo,
			'created_at'         => $this->createdAt,
		);
	}
}