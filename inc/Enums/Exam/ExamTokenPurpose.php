<?php
/**
 * Назначения ключей доступа: ключ принимается только на своём маршруте.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Enums\Exam;

enum ExamTokenPurpose: string {
	case Invitation = 'invitation';
	case Entry      = 'entry';
	case Result     = 'result';
	case Report     = 'report';
	case Payment    = 'payment';

	/** Подпись состояния для интерфейса без изменения сохраняемого значения. */
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
