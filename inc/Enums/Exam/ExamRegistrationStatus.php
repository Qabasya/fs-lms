<?php declare( strict_types=1 );

namespace Inc\Enums\Exam;

enum ExamRegistrationStatus: string {
	case Confirmed = 'confirmed';
	case Cancelled = 'cancelled';
	case Transferred = 'transferred';
	case Missed = 'missed';

	public function label(): string {
		return match ( $this ) {
			self::Confirmed => 'Запись подтверждена',
			self::Cancelled => 'Запись отменена',
			self::Transferred => 'Запись перенесена',
			self::Missed => 'Экзамен пропущен',
		};
	}
}
