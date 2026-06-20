<?php

declare( strict_types=1 );

namespace Inc\Enums\Import;

/**
 * Колонки CSV-импорта учеников — единый источник правды.
 *
 * Порядок cases() = порядок колонок в файле/шаблоне. Используется
 * импортёром (чтение строки + валидация заголовков), шаблоном таба
 * импорта (генерация образца CSV) и описанием формата.
 */
enum ImportColumn: string {

	// Ученик
	case LastName         = 'Фамилия';
	case FirstName        = 'Имя';
	case MiddleName       = 'Отчество';
	case BirthDate        = 'Дата рожд.';
	case Grade            = 'Класс';
	case School           = 'Школа';
	case Email            = 'Email';
	case Phone            = 'Телефон';
	case DocType          = 'Тип документа';
	case DocNumber        = 'Номер документа';
	case Inn              = 'ИНН';

	// Родитель
	case ParentLastName      = 'Родитель: Фамилия';
	case ParentFirstName     = 'Родитель: Имя';
	case ParentMiddleName    = 'Родитель: Отчество';
	case ParentBirthDate     = 'Родитель: Дата рожд.';
	case ParentEmail         = 'Родитель: Email';
	case ParentPhone         = 'Родитель: Телефон';
	case ParentDocType       = 'Родитель: Тип документа';
	case ParentDocNumber     = 'Родитель: Номер документа';
	case ParentDocIssuedBy   = 'Родитель: Кем выдан';
	case ParentDocIssuedDate = 'Родитель: Дата выдачи';
	case ParentInn           = 'Родитель: ИНН';
	case ParentAddress       = 'Родитель: Адрес регистрации';

	// Договор / зачисление / отчисление
	case Group        = 'Группа';
	case ContractNo   = '№ договора';
	case ContractDate = 'Дата договора';
	case OrderNo      = 'Номер приказа';
	case OrderDate    = 'Дата приказа';
	case EnrolledAt   = 'Дата зачисления';
	case ExpelledAt   = 'Дата отчисления';
	case ExpelReason  = 'Причина отчисления';

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

	/**
	 * Примеры значений для шаблона: две строки образца.
	 *
	 * @return array{0:string, 1:string}
	 */
	public function examples(): array {
		return match ( $this ) {
			self::LastName            => array( 'Иванов', 'Петров' ),
			self::FirstName           => array( 'Иван', 'Пётр' ),
			self::MiddleName          => array( 'Иванович', 'Петрович' ),
			self::BirthDate           => array( '15.03.2008', '20.07.2012' ),
			self::Grade               => array( '10', '6' ),
			self::School              => array( 'МАОУ СОШ №1', 'Гимназия №5' ),
			self::Email               => array( 'ivan@example.com', 'petr@example.com' ),
			self::Phone               => array( '+79000000000', '+79002222222' ),
			self::DocType             => array( 'Паспорт', 'Свидетельство о рождении' ),
			self::DocNumber           => array( '4500 123456', 'IV-АБ 654321' ),
			self::Inn                 => array( '500100732259', '390100732259' ),
			self::ParentLastName      => array( 'Иванова', 'Петрова' ),
			self::ParentFirstName     => array( 'Мария', 'Ольга' ),
			self::ParentMiddleName    => array( 'Петровна', 'Сергеевна' ),
			self::ParentBirthDate     => array( '10.05.1985', '22.11.1988' ),
			self::ParentEmail         => array( 'maria@example.com', 'olga@example.com' ),
			self::ParentPhone         => array( '+79001111111', '+79003333333' ),
			self::ParentDocType       => array( 'Паспорт', 'Паспорт' ),
			self::ParentDocNumber     => array( '4501 765432', '4502 112233' ),
			self::ParentDocIssuedBy   => array( 'ОВД г. Москвы', 'УФМС по г. Казань' ),
			self::ParentDocIssuedDate => array( '20.06.2010', '15.09.2009' ),
			self::ParentInn           => array( '770112345678', '160298765432' ),
			self::ParentAddress       => array( 'г. Москва, ул. Ленина, д. 1', 'г. Казань, ул. Баумана, д. 3' ),
			self::Group               => array( 'ОГЭ-1', 'Робо-2' ),
			self::ContractNo          => array( '2024-001', '2023-114' ),
			self::ContractDate        => array( '01.09.2024', '01.09.2023' ),
			self::OrderNo             => array( 'ПР-101', 'ПР-87' ),
			self::OrderDate           => array( '01.09.2024', '01.09.2023' ),
			self::EnrolledAt          => array( '01.09.2024', '01.09.2023' ),
			self::ExpelledAt          => array( '', '31.05.2024' ),
			self::ExpelReason         => array( '', 'Окончание курса' ),
		};
	}

	/**
	 * Строки-образцы в порядке колонок.
	 *
	 * @return array<int, string[]> Каждый элемент — строка значений по колонкам
	 */
	public static function exampleRows(): array {
		$rows = array( array(), array() );

		foreach ( self::cases() as $column ) {
			$values     = $column->examples();
			$rows[0][] = $values[0] ?? '';
			$rows[1][] = $values[1] ?? '';
		}

		return $rows;
	}
}
