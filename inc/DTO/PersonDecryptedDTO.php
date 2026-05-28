<?php

declare( strict_types=1 );

namespace Inc\DTO;

/**
 * Class PersonDecryptedDTO
 *
 * Расшифрованные PII-поля записи person для отображения.
 *
 * @package Inc\DTO
 *
 * ### Основные обязанности:
 *
 * 1. **Представление расшифрованных персональных данных** — инкапсулирует PII-поля,
 *    которые были расшифрованы для легитимного отображения.
 *
 * ### Архитектурная роль:
 *
 * Создаётся только через PersonReader — единственный санкционированный способ
 * читать PII для отображения пользователю (с соблюдением прав доступа).
 * Пустая строка = поле обезличено (enc = NULL) или отсутствует в базе данных.
 *
 * ### Примечания:
 *
 * - Используется в административной панели для отображения персональных данных
 *   сотрудникам, имеющим соответствующие права (Capability::ViewPII).
 * - Не должен создаваться напрямую контроллерами или репозиториями.
 */
readonly class PersonDecryptedDTO {

	/**
	 * Конструктор DTO.
	 *
	 * @param int    $personId  ID записи человека
	 * @param string $fullName  Полное имя (Фамилия Имя Отчество)
	 * @param string $pass  Данные документа (серия + номер)
	 * @param string $inn       ИНН (10 или 12 цифр)
	 * @param string $snils     СНИЛС (11 цифр)
	 * @param string $address   Адрес регистрации/проживания
	 * @param string $phone     Номер телефона
	 */
	public function __construct(
		public int    $personId,
		public string $fullName,
		public string $pass,
		public string $inn,
		public string $snils,
		public string $address,
		public string $phone,
	) {}
}