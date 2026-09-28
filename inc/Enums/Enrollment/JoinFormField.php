<?php

declare( strict_types=1 );

namespace Inc\Enums\Enrollment;

/**
 * Enum JoinFormField
 *
 * Поля формы родителя (атрибут `name` в templates/frontend/join.php) с подписями
 * для журнала. В журнал попадают только имена полей — не значения (там ПД).
 *
 * @package Inc\Enums\Enrollment
 */
enum JoinFormField: string {
	case StudentLastName   = 'student_last_name';
	case StudentFirstName  = 'student_first_name';
	case StudentMiddleName = 'student_middle_name';
	case School            = 'school';
	case Grade             = 'grade';
	case StudentBirthDate  = 'student_birth_date';
	case StudentPhone      = 'student_phone';
	case StudentDocType    = 'student_doc_type';
	case StudentDocNumber  = 'student_doc_number';
	case StudentInn        = 'student_inn';
	case ParentLastName    = 'parent_last_name';
	case ParentFirstName   = 'parent_first_name';
	case ParentMiddleName  = 'parent_middle_name';
	case ParentBirthDate   = 'parent_birth_date';
	case DocType           = 'doc_type';
	case DocNumber         = 'doc_number';
	case DocIssuedBy       = 'doc_issued_by';
	case DocIssuedDate     = 'doc_issued_date';
	case Inn               = 'inn';
	case Address           = 'address';
	case Phone             = 'phone';
	case Email             = 'email';
	case Consent           = 'consent_parent';

	public function label(): string {
		return match ( $this ) {
			self::StudentLastName   => 'фамилия ученика',
			self::StudentFirstName  => 'имя ученика',
			self::StudentMiddleName => 'отчество ученика',
			self::School            => 'школа',
			self::Grade             => 'класс',
			self::StudentBirthDate  => 'дата рождения ученика',
			self::StudentPhone      => 'телефон ученика',
			self::StudentDocType    => 'документ ученика',
			self::StudentDocNumber  => 'номер документа ученика',
			self::StudentInn        => 'ИНН ученика',
			self::ParentLastName    => 'фамилия родителя',
			self::ParentFirstName   => 'имя родителя',
			self::ParentMiddleName  => 'отчество родителя',
			self::ParentBirthDate   => 'дата рождения родителя',
			self::DocType           => 'тип документа родителя',
			self::DocNumber         => 'номер документа родителя',
			self::DocIssuedBy       => 'кем выдан',
			self::DocIssuedDate     => 'дата выдачи',
			self::Inn               => 'ИНН родителя',
			self::Address           => 'адрес',
			self::Phone             => 'телефон родителя',
			self::Email             => 'email родителя',
			self::Consent           => 'согласие на обработку ПД',
		};
	}
}
