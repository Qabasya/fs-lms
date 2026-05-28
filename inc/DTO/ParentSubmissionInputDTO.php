<?php

declare( strict_types=1 );

namespace Inc\DTO;

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
 * - Родитель может скорректировать данные ученика (studentFullName, studentBirthDate и т.д.)
 * - Данные родителя обязательны для заполнения
 */
readonly class ParentSubmissionInputDTO {

	/**
	 * Конструктор DTO.
	 *
	 * @param string $joinCode            Код присоединения (получен от ученика)
	 *
	 * @param string $parentFullName      Полное имя родителя (Фамилия Имя Отчество)
	 * @param string $parentBirthDate     Дата рождения родителя (Y-m-d)
	 * @param string $relationType        Тип родства (mother, father, guardian)
	 * @param string $docType             Тип документа родителя (pass, birth_certificate)
	 * @param string $docNumber           Номер документа родителя
	 * @param string $docIssuedBy         Кем выдан документ родителя
	 * @param string $docIssuedDate       Дата выдачи документа родителя (Y-m-d)
	 * @param string $inn                 ИНН родителя
	 * @param string $snils               СНИЛС родителя
	 * @param string $address             Адрес родителя
	 * @param string $phone               Телефон родителя
	 * @param string $email               Email родителя
	 *
	 * @param string $studentFullName     Скорректированное имя ученика
	 * @param string $studentBirthDate    Скорректированная дата рождения ученика (Y-m-d)
	 * @param string $studentDocType      Тип документа ученика
	 * @param string $studentDocNumber    Номер документа ученика
	 * @param string $studentInn          ИНН ученика (12 цифр)
	 */
	public function __construct(
		public string $joinCode,

		// Данные родителя/представителя
		public string $parentFullName,
		public string $parentBirthDate,
		public string $relationType,
		public string $docType,
		public string $docNumber,
		public string $docIssuedBy,
		public string $docIssuedDate,
		public string $inn,
		public string $snils,
		public string $address,
		public string $phone,
		public string $email,

		// Скорректированные данные ученика (может изменить родитель)
		public string $studentFullName,
		public string $studentBirthDate,
		public string $studentDocType,
		public string $studentDocNumber,
		public string $studentInn,
	) {}
}