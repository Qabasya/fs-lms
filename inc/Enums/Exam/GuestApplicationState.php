<?php declare( strict_types=1 );

namespace Inc\Enums\Exam;

enum GuestApplicationState: string {
	case Hold = 'hold';
	case AwaitingPayment = 'awaiting_payment';
	case PaymentPending = 'payment_pending';
	case Confirmed = 'confirmed';
	case ExpiredUnpaid = 'expired_unpaid';
	case Failed = 'failed';
	case PaidNeedsResolution = 'paid_needs_resolution';
	case Cancelled = 'cancelled';
	case Missed = 'missed';

	public function label(): string {
		return match ( $this ) {
			self::Hold => 'Место удерживается',
			self::AwaitingPayment => 'Место удерживается',
			self::PaymentPending => 'Ожидается подтверждение оплаты',
			self::Confirmed => 'Оплата получена, запись подтверждена',
			self::ExpiredUnpaid => 'Время брони истекло',
			self::Failed => 'Оплата не подтверждена',
			self::PaidNeedsResolution => 'Оплачено, требуется помощь',
			self::Cancelled => 'Запись отменена',
			self::Missed => 'Экзамен пропущен',
		};
	}

	public function holdsSeat(): bool {
		return self::Hold === $this || self::AwaitingPayment === $this || self::PaymentPending === $this;
	}

	public function isTerminal(): bool {
		return self::Confirmed === $this || self::ExpiredUnpaid === $this || self::Failed === $this || self::Cancelled === $this || self::Missed === $this;
	}
}
