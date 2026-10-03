<?php declare( strict_types=1 );

namespace Inc\Enums\Exam;

enum ManualResolutionKind: string {
	case Pending = 'pending';
	case Transferred = 'transferred';
	case RefundedOutside = 'refunded_outside';
	case Other = 'other';

	public function label(): string {
		return match ( $this ) {
			self::Pending => 'Ожидание',
			self::Transferred => 'Перенесено',
			self::RefundedOutside => 'Возврат вне системы',
			self::Other => 'Другое',
		};
	}
}
