<?php

declare( strict_types=1 );

namespace Inc\Enums\Print;

/**
 * Enum PrintField
 *
 * Поля, которые можно подставить в шаблон документа. В DOCX поле пишется
 * как `{{значение_кейса}}`, например `{{parent_full_name}}`.
 *
 * Поля с ПДн (паспорт, ИНН, адрес, контакты) расшифровываются только если
 * шаблон их действительно содержит — каждое раскрытие пишется в журнал ПД.
 *
 * @package Inc\Enums\Print
 */
enum PrintField: string {
	// Даты формирования
	case Today     = 'today';
	case TodayText = 'today_text';

	// Зачисление
	case ContractNo       = 'contract_no';
	case ContractDate     = 'contract_date';
	case ContractDateText = 'contract_date_text';
	case OrderNo          = 'order_no';
	case OrderDate        = 'order_date';
	case EnrolledDate     = 'enrolled_date';
	case Subject          = 'subject';
	case Program          = 'program';
	case Price            = 'price';
	case Group            = 'group';
	case Period           = 'period';
	case PeriodStart      = 'period_start';
	case PeriodEnd        = 'period_end';

	// Ученик
	case StudentFullName      = 'student_full_name';
	case StudentShortName     = 'student_short_name';
	case StudentLastName      = 'student_last_name';
	case StudentFirstName     = 'student_first_name';
	case StudentMiddleName    = 'student_middle_name';
	case StudentBirthDate     = 'student_birth_date';
	case StudentSchool        = 'student_school';
	case StudentGrade         = 'student_grade';
	case StudentDocType       = 'student_doc_type';
	case StudentDocNumber     = 'student_doc_number';
	case StudentDocIssuedBy   = 'student_doc_issued_by';
	case StudentDocIssuedDate = 'student_doc_issued_date';
	case StudentInn           = 'student_inn';

	// Родитель (заказчик)
	case ParentFullName      = 'parent_full_name';
	case ParentShortName     = 'parent_short_name';
	case ParentLastName      = 'parent_last_name';
	case ParentFirstName     = 'parent_first_name';
	case ParentMiddleName    = 'parent_middle_name';
	case ParentBirthDate     = 'parent_birth_date';
	case ParentDocType       = 'parent_doc_type';
	case ParentDocNumber     = 'parent_doc_number';
	case ParentDocIssuedBy   = 'parent_doc_issued_by';
	case ParentDocIssuedDate = 'parent_doc_issued_date';
	case ParentInn           = 'parent_inn';
	case ParentAddress       = 'parent_address';
	case ParentPhone         = 'parent_phone';
	case ParentEmail         = 'parent_email';

	/**
	 * Описание поля для справочника на странице.
	 */
	public function label(): string {
		return match ( $this ) {
			self::Today                => 'Дата формирования: 26.09.2026',
			self::TodayText            => 'Дата формирования: «26» сентября 2026 г.',
			self::ContractNo           => 'Номер договора',
			self::ContractDate         => 'Дата договора: 01.09.2026',
			self::ContractDateText     => 'Дата договора: «01» сентября 2026 г.',
			self::OrderNo              => 'Номер приказа о зачислении',
			self::OrderDate            => 'Дата приказа о зачислении',
			self::EnrolledDate         => 'Дата зачисления',
			self::Subject              => 'Предмет',
			self::Program              => 'Название программы (задаётся по предмету)',
			self::Price                => 'Стоимость месяца обучения (задаётся по предмету)',
			self::Group                => 'Группа',
			self::Period               => 'Учебный период',
			self::PeriodStart          => 'Начало учебного периода',
			self::PeriodEnd            => 'Конец учебного периода',
			self::StudentFullName      => 'ФИО полностью',
			self::StudentShortName     => 'Фамилия И. О.',
			self::StudentLastName      => 'Фамилия',
			self::StudentFirstName     => 'Имя',
			self::StudentMiddleName    => 'Отчество',
			self::StudentBirthDate     => 'Дата рождения',
			self::StudentSchool        => 'Школа',
			self::StudentGrade         => 'Класс',
			self::StudentDocType       => 'Вид документа',
			self::StudentDocNumber     => 'Серия и номер документа',
			self::StudentDocIssuedBy   => 'Кем выдан документ',
			self::StudentDocIssuedDate => 'Дата выдачи документа',
			self::StudentInn           => 'ИНН',
			self::ParentFullName       => 'ФИО полностью',
			self::ParentShortName      => 'Фамилия И. О.',
			self::ParentLastName       => 'Фамилия',
			self::ParentFirstName      => 'Имя',
			self::ParentMiddleName     => 'Отчество',
			self::ParentBirthDate      => 'Дата рождения',
			self::ParentDocType        => 'Вид документа',
			self::ParentDocNumber      => 'Серия и номер документа',
			self::ParentDocIssuedBy    => 'Кем выдан документ',
			self::ParentDocIssuedDate  => 'Дата выдачи документа',
			self::ParentInn            => 'ИНН',
			self::ParentAddress        => 'Адрес',
			self::ParentPhone          => 'Телефон',
			self::ParentEmail          => 'Email',
		};
	}

	/**
	 * Раздел справочника полей.
	 */
	public function group(): string {
		return match ( true ) {
			str_starts_with( $this->value, 'student_' ) => 'Ученик',
			str_starts_with( $this->value, 'parent_' )  => 'Родитель (заказчик)',
			str_starts_with( $this->value, 'today' )    => 'Дата формирования',
			default                                     => 'Зачисление',
		};
	}

	/**
	 * Поле хранится зашифрованным и раскрывается через PersonReader.
	 */
	public function isPii(): bool {
		return match ( $this ) {
			self::StudentDocNumber, self::StudentDocIssuedBy, self::StudentInn,
			self::ParentDocNumber, self::ParentDocIssuedBy, self::ParentInn,
			self::ParentAddress, self::ParentPhone, self::ParentEmail => true,
			default => false,
		};
	}

	/**
	 * Поля, сгруппированные по разделам справочника.
	 *
	 * @return array<string, self[]>
	 */
	public static function grouped(): array {
		$groups = array();
		foreach ( self::cases() as $field ) {
			$groups[ $field->group() ][] = $field;
		}

		return $groups;
	}
}
