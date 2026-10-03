<?php declare( strict_types=1 );

namespace Inc\Enums\Exam;

enum ExamPaymentState: string {
	case Pending = 'pending';
	case Paid = 'paid';
	case Failed = 'failed';
	case Cancelled = 'cancelled';

	public function label(): string {
		return match ( $this ) {
			self::Pending => 'Ожидание',
			self::Paid => 'Оплачено',
			self::Failed => 'Ошибка',
			self::Cancelled => 'Отменено',
		};
	}
}
