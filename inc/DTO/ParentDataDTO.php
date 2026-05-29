<?php

declare( strict_types=1 );

namespace Inc\DTO;

/**
 * Class ParentDataDTO
 *
 * Расшифрованные данные родителя/законного представителя из заявки.
 *
 * @package Inc\DTO
 *
 * ### Основные обязанности:
 *
 * 1. **Хранение персональных данных родителя** — инкапсулирует все данные, предоставленные родителем.
 * 2. **Преобразование массив <-> DTO** — методы fromArray() и toArray().
 *
 * ### Архитектурная роль:
 *
 * Создаётся сервисами после расшифровки parent_data_enc из таблицы applications,
 * а не репозиториями. Используется для отображения данных родителя в админ-панели
 * и для заполнения документов (договор, приказ).
 *
 * ### Примечания:
 *
 * - docType, docNumber, docIssuedBy, docIssuedDate — паспортные данные
 * - relationType — тип родства (мать, отец, опекун)
 * - Поля *_enc в БД хранятся зашифрованными, DTO содержит расшифрованные значения
 */
readonly class ParentDataDTO {

	/**
	 * Конструктор DTO.
	 *
	 * @param string $fullName       Полное имя (Фамилия Имя Отчество)
	 * @param string $birthDate      Дата рождения (Y-m-d)
	 * @param string $relationType   Тип родства (mother, father, guardian)
	 * @param string $docType        Тип документа (pass, birth_certificate)
	 * @param string $docNumber      Номер документа
	 * @param string $docIssuedBy    Кем выдан документ
	 * @param string $docIssuedDate  Дата выдачи документа (Y-m-d)
	 * @param string $inn            ИНН (10 или 12 цифр)
	 * @param string $address        Адрес регистрации/проживания
	 * @param string $phone          Номер телефона
	 * @param string $email          Email для связи
	 */
	public function __construct(
		public string $fullName,
		public string $birthDate,
		public string $relationType,
		public string $docType,
		public string $docNumber,
		public string $docIssuedBy,
		public string $docIssuedDate,
		public string $inn,
		public string $address,
		public string $phone,
		public string $email,
	) {}

	/**
	 * Создаёт DTO из массива данных.
	 *
	 * @param array<string, mixed> $data Ассоциативный массив с полями DTO
	 *
	 * @return static
	 */
	public static function fromArray( array $data ): static {
		return new static(
			fullName:      (string) ( $data['full_name'] ?? '' ),
			birthDate:     (string) ( $data['birth_date'] ?? '' ),
			relationType:  (string) ( $data['relation_type'] ?? '' ),
			docType:       (string) ( $data['doc_type'] ?? '' ),
			docNumber:     (string) ( $data['doc_number'] ?? '' ),
			docIssuedBy:   (string) ( $data['doc_issued_by'] ?? '' ),
			docIssuedDate: (string) ( $data['doc_issued_date'] ?? '' ),
			inn:           (string) ( $data['inn'] ?? '' ),
			address:       (string) ( $data['address'] ?? '' ),
			phone:         (string) ( $data['phone'] ?? '' ),
			email:         (string) ( $data['email'] ?? '' ),
		);
	}

	/**
	 * Преобразует DTO в массив для сериализации.
	 *
	 * @return array<string, string>
	 */
	public function toArray(): array {
		return array(
			'full_name'       => $this->fullName,
			'birth_date'      => $this->birthDate,
			'relation_type'   => $this->relationType,
			'doc_type'        => $this->docType,
			'doc_number'      => $this->docNumber,
			'doc_issued_by'   => $this->docIssuedBy,
			'doc_issued_date' => $this->docIssuedDate,
			'inn'             => $this->inn,
			'address'         => $this->address,
			'phone'           => $this->phone,
			'email'           => $this->email,
		);
	}
}