<?php

declare( strict_types=1 );

namespace Inc\Enums;

/**
 * Колонки CSV-импорта учеников — единый источник правды.
 *
 * Порядок cases() = порядок колонок в файле/шаблоне. Используется
 * импортёром (чтение строки + валидация заголовков), шаблоном таба
 * импорта (генерация образца CSV) и описанием формата.
 */
enum ImportColumn: string {

	case LastName         = 'Фамилия';
	case FirstName        = 'Имя';
	case MiddleName       = 'Отчество';
	case BirthDate        = 'Дата рожд.';
	case Grade            = 'Класс';
	case School           = 'Школа';
	case Email            = 'Email';
	case Phone            = 'Телефон';
	case ParentLastName   = 'Родитель: Фамилия';
	case ParentFirstName  = 'Родитель: Имя';
	case ParentMiddleName = 'Родитель: Отчество';
	case ParentEmail      = 'Родитель: Email';
	case ParentPhone      = 'Родитель: Телефон';
	case Group            = 'Группа';
	case ContractNo       = '№ договора';
	case ContractDate     = 'Дата договора';
	case EnrolledAt       = 'Дата зачисления';
	case ExpelledAt       = 'Дата отчисления';
	case ExpelReason      = 'Причина отчисления';

	/**
	 * Все заголовки в порядке файла.
	 *
	 * @return string[]
	 */
	public static function headers(): array {
		return array_map( static fn( self $c ): string => $c->value, self::cases() );
	}

	/**
	 * Обязательные заголовки (минимум для создания записи).
	 *
	 * @return string[]
	 */
	public static function required(): array {
		return array(
			self::LastName->value,
			self::FirstName->value,
			self::Group->value,
			self::ContractNo->value,
			self::ParentLastName->value,
			self::ParentFirstName->value,
		);
	}
}
