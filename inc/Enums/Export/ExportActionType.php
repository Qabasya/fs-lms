<?php

declare( strict_types=1 );

namespace Inc\Enums\Export;

enum ExportActionType: string {
	case Single = 'single';
	case Bulk   = 'bulk';

	public function label(): string {
		return match ( $this ) {
			self::Single => 'Одна запись',
			self::Bulk   => 'Массовый',
		};
	}
}
