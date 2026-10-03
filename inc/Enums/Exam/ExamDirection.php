<?php declare( strict_types=1 );

namespace Inc\Enums\Exam;

enum ExamDirection: string {
	case Ege = 'ege';
	case Oge = 'oge';

	public function label(): string {
		return match ( $this ) {
			self::Ege => 'ЕГЭ',
			self::Oge => 'ОГЭ',
		};
	}

	public function grade(): int {
		return match ( $this ) {
			self::Ege => 11,
			self::Oge => 9,
		};
	}
}
