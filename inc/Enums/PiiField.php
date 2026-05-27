<?php
declare(strict_types=1);
namespace Inc\Enums;

enum PiiField: string {
	case FullName = 'full_name';
	case Pass = 'pass';
	case Inn      = 'inn';
	case Snils    = 'snils';
	case Address  = 'address';
	case Phone    = 'phone';

	/**
	 * Возвращает регулярное выражение или внутренний токен шаблона маски
	 */
	public function maskPattern(): string
	{
		return match ($this) {
			self::FullName => 'none',
			self::Pass => '4-2-4', // Оставить первые 4 и последние 4
			self::Inn      => 'last-4',
			self::Snils    => 'last-2',
			self::Address  => 'city-only',
			self::Phone    => 'phone-ru',
		};
	}
}
