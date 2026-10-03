<?php declare( strict_types=1 );

namespace Inc\Enums\Exam;

enum ExamAudience: string {
	case Student = 'student';
	case Guest = 'guest';

	public function label(): string {
		return match ( $this ) {
			self::Student => 'Ученик',
			self::Guest => 'Гость',
		};
	}
}
