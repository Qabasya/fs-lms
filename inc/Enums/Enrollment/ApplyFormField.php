<?php

declare( strict_types=1 );

namespace Inc\Enums\Enrollment;

/**
 * Enum ApplyFormField
 *
 * Поля формы заявки (атрибут `name` в templates/frontend/apply-fields.php) с подписями
 * для журнала. В журнал попадают только имена полей — не значения.
 *
 * @package Inc\Enums\Enrollment
 */
enum ApplyFormField: string {
	case Subject    = 'subject_key';
	case LastName   = 'last_name';
	case FirstName  = 'first_name';
	case MiddleName = 'middle_name';
	case Email      = 'email';
	case Phone      = 'phone';
	case BirthDate  = 'birth_date';
	case School     = 'school';
	case Grade      = 'grade';
	case Username   = 'username';
	case Password   = 'password';
	case OtpCode    = 'otp_code';

	public function label(): string {
		return match ( $this ) {
			self::Subject    => 'направление',
			self::LastName   => 'фамилия',
			self::FirstName  => 'имя',
			self::MiddleName => 'отчество',
			self::Email      => 'email',
			self::Phone      => 'телефон',
			self::BirthDate  => 'дата рождения',
			self::School     => 'школа',
			self::Grade      => 'класс',
			self::Username   => 'логин',
			self::Password   => 'пароль',
			self::OtpCode    => 'код из письма',
		};
	}
}
