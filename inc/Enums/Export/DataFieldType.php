<?php

declare( strict_types=1 );

namespace Inc\Enums\Export;

enum DataFieldType: string {
	case LastName = 'last_name';
	case FirstName = 'first_name';
	case MiddleName = 'middle_name';
	case BirthDate = 'birth_date';
	case School = 'school';
	case Grade = 'grade';
	case Email = 'email';
	case Phone = 'phone';
	case DocNumber = 'doc_number';
	case Inn = 'inn';
	case Address = 'address';
	case DocIssuedBy = 'doc_issued_by';
	case DocIssuedDate = 'doc_issued_date';
	case Login = 'login';
	case Password = 'password';

	public function label(): string {
		return match ( $this ) {
			self::LastName => 'Фамилия',
			self::FirstName => 'Имя',
			self::MiddleName => 'Отчество',
			self::BirthDate => 'Дата рождения',
			self::School => 'Школа',
			self::Grade => 'Класс',
			self::Email => 'E-mail',
			self::Phone => 'Телефон',
			self::DocNumber => 'Номер документа',
			self::Inn => 'ИНН',
			self::Address => 'Адрес',
			self::DocIssuedBy => 'Кем выдан документ',
			self::DocIssuedDate => 'Дата выдачи документа',
			self::Login => 'Логин',
			self::Password => 'Пароль',
		};
	}
}
