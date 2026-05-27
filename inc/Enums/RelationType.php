<?php

declare(strict_types=1);

namespace Inc\Enums;

enum RelationType: string {
	/** Мать */
	case Mother = 'mother';

	/** Отец */
	case Father = 'father';

	/** Опекун (законный представитель) */
	case Guardian = 'guardian';

	/** Бабушка или дедушка */
	case Grandparent = 'grandparent';

	/** Иной тип родства */
	case Other = 'other';

	/**
	 * Возвращает человекочитаемый лейбл на русском языке.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::Mother      => 'Мать',
			self::Father      => 'Отец',
			self::Guardian    => 'Опекун',
			self::Grandparent => 'Бабушка/Дедушка',
			self::Other       => 'Другое',
		};
	}
}
