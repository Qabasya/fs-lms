<?php

declare( strict_types=1 );

namespace Inc\Enums;

enum EmailStatus: string {
	case Success = 'success';
	case Failure = 'failed';

	public function label(): string {
		return match ( $this ) {
			self::Success => 'Отправлено',
			self::Failure => 'Ошибка',
		};
	}

}
