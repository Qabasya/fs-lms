<?php declare( strict_types=1 );

namespace Inc\Enums\Exam;

enum ExamPaymentState: string {
	case Pending = 'pending';
	case Paid = 'paid';
	case Failed = 'failed';
	case Cancelled = 'cancelled';

	/** Подпись оплаты на доске сотрудника; обычные краткие подписи сохраняются. */
	public function boardLabel(): string {
		return match ( $this ) {
			self::Pending => 'Ожидает оплаты',
			self::Paid => 'Оплачено',
			self::Failed => 'Оплата не прошла',
			self::Cancelled => 'Оплата отменена',
		};
	}

	public function label(): string {
		return match ( $this ) {
			self::Pending => 'Ожидание',
			self::Paid => 'Оплачено',
			self::Failed => 'Ошибка',
			self::Cancelled => 'Отменено',
		};
	}
}
