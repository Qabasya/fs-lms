<?php
/**
 * Виды ручного решения конфликтов регистрации и оплаты.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Enums\Exam;

enum ManualResolutionKind: string {
	case Pending         = 'pending';
	case Transferred     = 'transferred';
	case RefundedOutside = 'refunded_outside';
	case Other           = 'other';
	/** Бронь принята сотрудником без оплаты (подарочное посещение): запись подтверждена, заказа магазина нет. */
	case AcceptedManually = 'accepted_manually';

	/** Подпись для интерфейса без изменения сохраняемого значения. */
	public function label(): string {
		return match ( $this ) {
			self::Pending => 'Ожидание',
			self::Transferred => 'Перенесено',
			self::RefundedOutside => 'Возврат вне системы',
			self::Other => 'Другое',
			self::AcceptedManually => 'Принято без оплаты',
		};
	}
}
