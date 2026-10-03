<?php declare( strict_types=1 );

namespace Inc\Enums\Exam;

enum ExamTokenPurpose: string {
	case Invitation = 'invitation';
	case Entry = 'entry';
	case Result = 'result';
	case Report = 'report';
	case Payment = 'payment';

	public function label(): string {
		return match ( $this ) {
			self::Invitation => 'Приглашение',
			self::Entry => 'Вход',
			self::Result => 'Результат',
			self::Report => 'Отчёт',
			self::Payment => 'Оплата',
		};
	}
}
