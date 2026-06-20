<?php

declare( strict_types=1 );

namespace Inc\Enums\Subject;

enum TaxonomyDisplayType: string {
	case Select   = 'select';
	case Radio    = 'radio';
	case Checkbox = 'checkbox';

	public function label(): string {
		return match ( $this ) {
			self::Select   => 'Выпадающий список',
			self::Radio    => 'Один выбор',
			self::Checkbox => 'Чекбокс',
		};
	}
}
