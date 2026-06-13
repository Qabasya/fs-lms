<?php

declare( strict_types=1 );

namespace Inc\DTO\Person;

/**
 * Class ParentSubmissionInputDTO
 *
 * Входные данные из публичной формы родителя (/lms/join/{code}).
 *
 * @package Inc\DTO
 *
 * ### Основные обязанности:
 *
 * 1. **Типобезопасная передача** — инкапсулирует данные, введённые родителем при присоединении к заявке.
 *
 * ### Архитектурная роль:
 *
 * Передаётся в ApplicationService::submitParentData() для обновления заявки.
 * Содержит данные родителя + скорректированные данные ученика + JOIN-код.
 * Все поля уже санитизированы через Sanitizer trait до создания DTO.
 *
 * ### Примечания:
 *
 * - joinCode — открытый код присоединения (не хэш), полученный от ученика
 * - Родитель может скорректировать данные ученика (имя, дата рождения и т.д.)
 * - Данные родителя обязательны для заполнения
 */
readonly class ParentSubmissionInputDTO {

	/**
	 * Конструктор DTO.
	 *
	 * @param string $joinCode            Код присоединения (получен от ученика)
	 *
	 * @param string $parentLastName      Фамилия родителя
	 * @param string $parentFirstName     Имя родителя
	 * @param string $parentMiddleName    Отчество родителя
	 * @param string $parentBirthDate     Дата рождения родителя (Y-m-d)
	 * @param string $docType             Тип документа родителя (pass, birth_certificate)
	 * @param string $docNumber           Номер документа родителя
	 * @param string $docIssuedBy         Кем выдан документ родителя
	 * @param string $docIssuedDate       Дата выдачи документа родителя (Y-m-d)
	 * @param string $inn                 ИНН родителя
	 * @param string $address             Адрес родителя
	 * @param string $phone               Телефон родителя
	 * @param string $email               Email родителя
	 *
	 * @param string $studentLastName     Скорректированная фамилия ученика
	 * @param string $studentFirstName    Скорректированное имя ученика
	 * @param string $studentMiddleName   Скорректированное отчество ученика
	 * @param string $studentBirthDate    Скорректированная дата рождения ученика (Y-m-d)
	 * @param string $studentDocType      Тип документа ученика
	 * @param string $studentDocNumber    Номер документа ученика
	 * @param string $studentInn          ИНН ученика (12 цифр)
	 */
	public function __construct(
		public string $joinCode,

		// Данные родителя/представителя
		public string $parentLastName,
		public string $parentFirstName,
		public string $parentMiddleName,
		public string $parentBirthDate,
		public string $docType,
		public string $docNumber,
		public string $docIssuedBy,
		public string $docIssuedDate,
		public string $inn,
		public string $address,
		public string $phone,
		public string $email,

		// Скорректированные данные ученика (может изменить родитель)
		public string $studentLastName,
		public string $studentFirstName,
		public string $studentMiddleName,
		public string $studentBirthDate,
		public string $studentDocType,
		public string $studentDocNumber,
		public string $studentInn,
	) {}

	public function parentFullName(): string {
		return trim( "$this->parentLastName $this->parentFirstName $this->parentMiddleName" );
	}

	public function studentFullName(): string {
		return trim( "$this->studentLastName $this->studentFirstName $this->studentMiddleName" );
	}
}
