<?php

declare( strict_types=1 );

namespace Inc\Enums;

enum AuthResult: string {
	case Success = 'success';
	case Failure = 'failure';

	public function label(): string {
		return match ( $this ) {
			self::Success => 'Успех',
			self::Failure => 'Неудача',
		};
	}
}
