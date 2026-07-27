<?php

declare( strict_types=1 );

namespace Inc\Enums\Import;

/**
 * Режим CSV-импорта учеников.
 *
 * - Archive  — существующий режим: архивные записи прошлых лет, без WP-учёток.
 * - Enrolled — полное зачисление: та же строка + создание WP-учёток ученика и родителя.
 */
enum ImportMode: string {

	case Archive  = 'archive';
	case Enrolled = 'enrolled';

	public function label(): string {
		return match ( $this ) {
			self::Archive  => 'Архивные записи',
			self::Enrolled => 'Полное зачисление',
		};
	}

	/**
	 * Разбирает значение из запроса; неизвестное/пустое значение → Archive.
	 */
	public static function fromRequest( string $value ): self {
		return self::tryFrom( $value ) ?? self::Archive;
	}
}
