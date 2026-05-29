<?php

declare( strict_types=1 );

namespace Inc\DTO;

/**
 * Class PersonDTO
 *
 * Row-DTO строки таблицы fs_lms_persons.
 *
 * @package Inc\DTO
 *
 * ### Основные обязанности:
 *
 * 1. **Хранение данных о физическом лице** — представляет запись из таблицы persons.
 * 2. **Преобразование массив <-> DTO** — методы fromArray() и toArray().
 *
 * ### Архитектурная роль:
 *
 * Используется в PersonRepository для передачи данных о физических лицах
 * (ученики, родители, преподаватели).
 *
 * ### Примечания:
 *
 * - Поля *_enc хранятся как бинарные строки (BLOB) — зашифрованные персональные данные.
 * - Расшифровка выполняется только через PersonDecryptedDTO, создаваемый PersonReader
 *   с соблюдением прав доступа (Capability::ViewPII).
 * - doc_number_hash и inn_hash — хэши для поиска по документам без расшифровки.
 * - deleted_at — поле для мягкого удаления (NULL = запись активна)
 */
readonly class PersonDTO {

	/**
	 * Конструктор DTO.
	 *
	 * @param int         $id            ID записи
	 * @param int|null    $wpUserId      ID пользователя WordPress (если связан)
	 * @param string|null $email         Email (дублируется для поиска)
	 * @param string|null $fullNameEnc   Зашифрованное ФИО
	 * @param string|null $docNumberEnc  Зашифрованный номер документа
	 * @param string|null $innEnc        Зашифрованный ИНН
	 * @param string|null $addressEnc    Зашифрованный адрес
	 * @param string|null $phoneEnc      Зашифрованный телефон
	 * @param string|null $docNumberHash Хэш номера документа (для поиска)
	 * @param string|null $innHash       Хэш ИНН (для поиска)
	 * @param string|null $deletedAt     Дата мягкого удаления (NULL — активна)
	 * @param string      $createdAt     Дата создания записи
	 * @param string      $updatedAt     Дата обновления записи
	 */
	public function __construct(
		public int     $id,
		public ?int    $wpUserId,
		public ?string $email,
		public ?string $fullNameEnc,
		public ?string $docNumberEnc,
		public ?string $innEnc,
		public ?string $addressEnc,
		public ?string $phoneEnc,
		public ?string $docNumberHash,
		public ?string $innHash,
		public ?string $deletedAt,
		public string  $createdAt,
		public string  $updatedAt,
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
			id:            (int) $row['id'],
			wpUserId:      isset( $row['wp_user_id'] ) ? (int) $row['wp_user_id'] : null,
			email:         isset( $row['email'] ) ? (string) $row['email'] : null,
			fullNameEnc:   isset( $row['full_name_enc'] ) ? (string) $row['full_name_enc'] : null,
			docNumberEnc:  isset( $row['doc_number_enc'] ) ? (string) $row['doc_number_enc'] : null,
			innEnc:        isset( $row['inn_enc'] ) ? (string) $row['inn_enc'] : null,
			addressEnc:    isset( $row['address_enc'] ) ? (string) $row['address_enc'] : null,
			phoneEnc:      isset( $row['phone_enc'] ) ? (string) $row['phone_enc'] : null,
			docNumberHash: isset( $row['doc_number_hash'] ) ? (string) $row['doc_number_hash'] : null,
			innHash:       isset( $row['inn_hash'] ) ? (string) $row['inn_hash'] : null,
			deletedAt:     isset( $row['deleted_at'] ) ? (string) $row['deleted_at'] : null,
			createdAt:     (string) $row['created_at'],
			updatedAt:     (string) $row['updated_at'],
		);
	}

	/**
	 * Преобразует DTO в массив для вставки/обновления в БД.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'id'              => $this->id,
			'wp_user_id'      => $this->wpUserId,
			'email'           => $this->email,
			'full_name_enc'   => $this->fullNameEnc,
			'doc_number_enc'  => $this->docNumberEnc,
			'inn_enc'         => $this->innEnc,
			'address_enc'     => $this->addressEnc,
			'phone_enc'       => $this->phoneEnc,
			'doc_number_hash' => $this->docNumberHash,
			'inn_hash'        => $this->innHash,
			'deleted_at'      => $this->deletedAt,
			'created_at'      => $this->createdAt,
			'updated_at'      => $this->updatedAt,
		);
	}
}