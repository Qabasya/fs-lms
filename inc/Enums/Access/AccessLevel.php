<?php

declare( strict_types=1 );

namespace Inc\Enums\Access;

enum AccessLevel: string {
	case None        = 'none';
	case Read        = 'read';
	case ReadSubmit  = 'read_submit';

	/**
	 * Порядок уровней: `None` < `Read` < `ReadSubmit`. По нему шов `fs_lms_lesson_access`
	 * отличает понижение доступа (разрешено) от повышения (игнорируется).
	 */
	public function rank(): int {
		return match ( $this ) {
			self::None       => 0,
			self::Read       => 1,
			self::ReadSubmit => 2,
		};
	}
}
