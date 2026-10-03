<?php declare( strict_types=1 );

namespace Inc\Enums\Exam;

enum ExamSessionStatus: string {
	case Open = 'open';
	case Cancelled = 'cancelled';
	case Completed = 'completed';

	public function label(): string {
		return match ( $this ) {
			self::Open => 'Открыт',
			self::Cancelled => 'Отменён',
			self::Completed => 'Завершён',
		};
	}
}
