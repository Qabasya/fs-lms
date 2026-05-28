<?php

declare( strict_types=1 );

namespace Inc\DTO;

/**
 * Class RepresentativeInputDTO
 *
 * Входные данные из формы добавления/замены представителя в административной панели.
 *
 * @package Inc\DTO
 *
 * ### Основные обязанности:
 *
 * 1. **Типобезопасная передача** — инкапсулирует данные законного представителя,
 *    добавляемого или заменяемого сотрудником в админ-панели.
 *
 * ### Архитектурная роль:
 *
 * Используется в RelationshipService::addRepresentative() для добавления
 * нового опекуна и в replaceRepresentative() для замены существующего.
 * Все поля уже санитизированы через Sanitizer trait до создания DTO.
 *
 * ### Примечания:
 *
 * - studentPersonId — ID ученика, которому добавляется/заменяется представитель
 * - relationType — тип родства (mother, father, guardian, grandparent, other)
 * - Данные представителя могут быть как новыми (создаётся новая запись в persons),
 *   так и существующими (если представитель уже есть в системе)
 */
readonly class RepresentativeInputDTO {

	/**
	 * Конструктор DTO.
	 *
	 * @param int    $studentPersonId ID ученика (из таблицы persons)
	 * @param string $fullName        Полное имя представителя (Фамилия Имя Отчество)
	 * @param string $birthDate       Дата рождения представителя (Y-m-d)
	 * @param string $relationType    Тип родства (mother, father, guardian)
	 * @param string $docType         Тип документа (pass, birth_certificate)
	 * @param string $docNumber       Номер документа представителя
	 * @param string $docIssuedBy     Кем выдан документ
	 * @param string $docIssuedDate   Дата выдачи документа (Y-m-d)
	 * @param string $inn             ИНН представителя
	 * @param string $snils           СНИЛС представителя
	 * @param string $address         Адрес представителя
	 * @param string $phone           Телефон представителя
	 * @param string $email           Email представителя
	 */
	public function __construct(
		public int    $studentPersonId,
		public string $fullName,
		public string $birthDate,
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
	) {}
}