<?php
/**
 * Направления официального экзамена.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Enums\Exam;

enum ExamDirection: string {
	case Ege = 'ege';
	case Oge = 'oge';

	/** Подпись для интерфейса без изменения сохраняемого значения. */
	public function label(): string {
		return match ( $this ) {
			self::Ege => 'ЕГЭ',
			self::Oge => 'ОГЭ',
		};
	}

	/** Класс аудитории направления: 11 для ЕГЭ, 9 для ОГЭ. */
	public function grade(): int {
		return match ( $this ) {
			self::Ege => 11,
			self::Oge => 9,
		};
	}
}
