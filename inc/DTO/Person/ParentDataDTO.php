<?php

declare( strict_types=1 );

namespace Inc\DTO\Person;

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
 * - Поля *_enc в БД хранятся зашифрованными, DTO содержит расшифрованные значения
 */
readonly class ParentDataDTO {

	/**
	 * Конструктор DTO.
	 *
	 * @param string $lastName       Фамилия родителя
	 * @param string $firstName      Имя родителя
	 * @param string $middleName     Отчество родителя
	 * @param string $birthDate      Дата рождения (Y-m-d)
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
		public string $lastName,
		public string $firstName,
		public string $middleName,
		public string $birthDate,
		public string $docType,
		public string $docNumber,
		public string $docIssuedBy,
		public string $docIssuedDate,
		public string $inn,
		public string $address,
		public string $phone,
		public string $email,
	) {}

	public function fullName(): string {
		return trim( "$this->lastName $this->firstName $this->middleName" );
	}

	/**
	 * Создаёт DTO из массива данных.
	 *
	 * @param array<string, mixed> $data Ассоциативный массив с полями DTO
	 *
	 * @return static
	 */
	public static function fromArray( array $data ): static {
		// Обратная совместимость: если отдельных полей нет, разбиваем full_name
		$parts      = explode( ' ', (string) ( $data['full_name'] ?? '' ), 3 );
		$lastName   = (string) ( $data['last_name']   ?? $parts[0] ?? '' );
		$firstName  = (string) ( $data['first_name']  ?? $parts[1] ?? '' );
		$middleName = (string) ( $data['middle_name'] ?? $parts[2] ?? '' );

		return new static(
			lastName:      $lastName,
			firstName:     $firstName,
			middleName:    $middleName,
			birthDate:     (string) ( $data['birth_date']      ?? '' ),
			docType:       (string) ( $data['doc_type']        ?? '' ),
			docNumber:     (string) ( $data['doc_number']      ?? '' ),
			docIssuedBy:   (string) ( $data['doc_issued_by']   ?? '' ),
			docIssuedDate: (string) ( $data['doc_issued_date'] ?? '' ),
			inn:           (string) ( $data['inn']             ?? '' ),
			address:       (string) ( $data['address']         ?? '' ),
			phone:         (string) ( $data['phone']           ?? '' ),
			email:         (string) ( $data['email']           ?? '' ),
		);
	}

	/**
	 * Преобразует DTO в массив для сериализации.
	 *
	 * @return array<string, string>
	 */
	public function toArray(): array {
		return array(
			'last_name'       => $this->lastName,
			'first_name'      => $this->firstName,
			'middle_name'     => $this->middleName,
			'full_name'       => $this->fullName(),
			'birth_date'      => $this->birthDate,
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
