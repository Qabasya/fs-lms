<?php

declare( strict_types=1 );

namespace Inc\DTO;

/**
 * Class StudentDataDTO
 *
 * Расшифрованные данные ученика из заявки.
 *
 * @package Inc\DTO
 *
 * ### Основные обязанности:
 *
 * 1. **Хранение персональных данных ученика** — инкапсулирует все данные,
 *    предоставленные учеником при создании заявки.
 * 2. **Преобразование массив <-> DTO** — методы fromArray() и toArray().
 *
 * ### Архитектурная роль:
 *
 * Создаётся сервисами после расшифровки student_data_enc из таблицы applications,
 * а не репозиториями. Используется для отображения данных ученика в админ-панели
 * и в процессе зачисления.
 *
 * ### Примечания:
 *
 * - docType, docNumber — данные документа, удостоверяющего личность
 * - grade — класс обучения (1-11)
 * - Поля *_enc в БД хранятся зашифрованными, DTO содержит расшифрованные значения
 */
readonly class StudentDataDTO {

	/**
	 * Конструктор DTO.
	 *
	 * @param string $fullName  Полное имя ученика (Фамилия Имя Отчество)
	 * @param string $email     Email ученика (для связи и уведомлений)
	 * @param string $school    Название школы/учебного заведения
	 * @param int    $grade     Класс обучения (1-11)
	 * @param string $birthDate Дата рождения (Y-m-d)
	 * @param string $docType   Тип документа (pass, birth_certificate)
	 * @param string $docNumber Номер документа
	 * @param string $inn       ИНН ученика (12 цифр)
	 */
	public function __construct(
		public string $fullName,
		public string $email,
		public string $school,
		public int    $grade,
		public string $birthDate,
		public string $docType,
		public string $docNumber,
		public string $inn,
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
			fullName:  (string) ( $data['full_name'] ?? '' ),
			email:     (string) ( $data['email'] ?? '' ),
			school:    (string) ( $data['school'] ?? '' ),
			grade:     (int) ( $data['grade'] ?? 0 ),
			birthDate: (string) ( $data['birth_date'] ?? '' ),
			docType:   (string) ( $data['doc_type'] ?? '' ),
			docNumber: (string) ( $data['doc_number'] ?? '' ),
			inn:       (string) ( $data['inn'] ?? '' ),
		);
	}

	/**
	 * Преобразует DTO в массив для сериализации.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'full_name'  => $this->fullName,
			'email'      => $this->email,
			'school'     => $this->school,
			'grade'      => $this->grade,
			'birth_date' => $this->birthDate,
			'doc_type'   => $this->docType,
			'doc_number' => $this->docNumber,
			'inn'        => $this->inn,
		);
	}
}