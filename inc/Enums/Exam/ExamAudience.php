<?php
/**
 * Аудитории официального экзамена: ученики и гости.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Enums\Exam;

enum ExamAudience: string {
	case Student = 'student';
	case Guest   = 'guest';

	/** Подпись состояния для интерфейса без изменения сохраняемого значения. */
	public function label(): string {
		return match ( $this ) {
			self::Student => 'Ученик',
			self::Guest => 'Гость',
		};
	}
}
