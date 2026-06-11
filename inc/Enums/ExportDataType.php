<?php

declare( strict_types=1 );

namespace Inc\Enums;

enum ExportDataType: string {
	case Groups   = 'groups';
	case Students = 'students';
	case Parents  = 'parents';
	case Archive  = 'archive';
	case Logs     = 'log';

	public function label(): string {
		return match ( $this ) {
			self::Groups   => 'Группы',
			self::Students => 'Ученики',
			self::Parents  => 'Родители',
			self::Archive  => 'Архив',
			self::Logs     => 'Журнал',
		};
	}
}
