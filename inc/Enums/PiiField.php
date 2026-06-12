<?php
declare(strict_types=1);
namespace Inc\Enums;

enum PiiField: string {
	case FullName    = 'full_name';
	case Pass        = 'pass';
	case Inn         = 'inn';
	case Address     = 'address';
	case Phone       = 'phone';
	case Password    = 'password';
	case Login       = 'login';
	case StudentData = 'student_data';

	/**
	 * Возвращает регулярное выражение или внутренний токен шаблона маски
	 */
	public function maskPattern(): string {
		return match ( $this ) {
			self::FullName    => 'none',
			self::Pass        => '4-2-4',
			self::Inn         => 'last-4',
			self::Address     => 'city-only',
			self::Phone       => 'phone-ru',
			self::Password    => 'all-hidden',
			self::Login       => 'all-hidden',
			self::StudentData => 'none',
		};
	}
}
