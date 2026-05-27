<?php

declare(strict_types=1);

namespace Inc\Enums;

enum ApplicationStatus: string {
	/** Ожидание подтверждения от родителя */
	case PendingParent = 'pending_parent';

	/** Готово к проверке (отправлено на рассмотрение) */
	case ReadyForReview = 'ready_for_review';

	/** Процесс зачисления (одобрено, оформление документов) */
	case Enrolling = 'enrolling';

	/** Успешно зачислен (конечный статус) */
	case Converted = 'converted';

	/** Отклонено (конечный статус) */
	case Rejected = 'rejected';

	/** Истекло время ожидания (конечный статус) */
	case Expired = 'expired';

	/**
	 * Проверяет, разрешён ли переход из текущего статуса в следующий.
	 * Реализует правила конечного автомата для статусов заявки.
	 *
	 * @param self $next Целевой статус
	 *
	 * @return bool
	 */
	public function canTransitionTo( self $next ): bool {
		return match ( $this ) {
			// Из ожидания родителя можно отправить на проверку или истечь по времени
			self::PendingParent => ( self::ReadyForReview === $next || self::Expired === $next ),

			// На проверке можно одобрить (на зачисление), отклонить или дать истечь
			self::ReadyForReview => ( self::Enrolling === $next || self::Rejected === $next || self::Expired === $next ),

			// В процессе зачисления можно завершить зачисление (успех) или вернуть на проверку
			self::Enrolling => ( self::Converted === $next || self::ReadyForReview === $next ),

			// Терминальные статусы — из них переходы запрещены
			self::Converted, self::Rejected, self::Expired => false,
		};
	}
}
