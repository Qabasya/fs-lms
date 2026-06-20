<?php

declare( strict_types=1 );


namespace Inc\Enums\Enrollment;

enum ExpulsionReasons: string {

	case End = 'Окончание курса';
	case Transfer = 'Перевод';
	case OwnRequest = 'По собственному желанию';
	case Other = 'Другое';

	public static function values(): array {
		return array_map(
			static fn( self $case ) => $case->value,
			self::cases()
		);
	}

}
