<?php

declare( strict_types=1 );

namespace Inc\DTO\Person;

/**
 * Class PersonDTO
 *
 * Data Transfer Object для основной информации о физическом лице (Person).
 *
 * @package Inc\DTO\Person
 *
 * ### Основные обязанности:
 *
 * 1. **Хранение основных данных лица** — ФИО, дата рождения, статус (ученик/родитель), школа, класс.
 * 2. **Преобразование массив <-> DTO** — методы fromArray() и toArray().
 * 3. **Формирование полного имени** — метод fullName().
 *
 * ### Архитектурная роль:
 *
 * Используется в PersonRepository для передачи базовой информации о лице
 * (без документов и контактов — они в отдельной таблице person_documents).
 *
 * ### Поля:
 *
 * - id — ID записи
 * - wpUserId — ID пользователя WordPress (если привязан)
 * - lastName, firstName, middleName — ФИО
 * - birthDate — дата рождения (Y-m-d)
 * - isStudent — флаг: true — ученик, false — родитель/представитель
 * - school — школа (для учеников)
 * - grade — класс (для учеников)
 * - expelledAt — дата отчисления (для учеников, если применимо)
 * - createdAt, updatedAt — временные метки
 */
readonly class PersonDTO {

	/**
	 * Конструктор DTO.
	 *
	 * @param int         $id          ID записи
	 * @param int|null    $wpUserId    ID пользователя WP (если привязан)
	 * @param string      $lastName    Фамилия
	 * @param string      $firstName   Имя
	 * @param string|null $middleName  Отчество (может быть null)
	 * @param string|null $birthDate   Дата рождения (Y-m-d)
	 * @param bool        $isStudent   Флаг: true — ученик, false — родитель/представитель
	 * @param string|null $school      Школа (только для учеников)
	 * @param string|null $grade       Класс (только для учеников)
	 * @param string|null $expelledAt  Дата отчисления (только для учеников)
	 * @param string      $createdAt   Дата создания записи
	 * @param string      $updatedAt   Дата обновления записи
	 */
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

	/**
	 * Возвращает полное имя в формате "Фамилия Имя Отчество".
	 *
	 * @return string
	 */
	public function fullName(): string {
		return trim( "{$this->lastName} {$this->firstName} " . ( $this->middleName ?? '' ) );
	}

	/**
	 * Создаёт DTO из массива данных (например, из результата SQL-запроса).
	 *
	 * @param array<string, mixed> $row Ассоциативный массив с полями таблицы
	 *
	 * @return static
	 */
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

	/**
	 * Преобразует DTO в массив для вставки/обновления в БД.
	 *
	 * @return array<string, mixed>
	 */
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