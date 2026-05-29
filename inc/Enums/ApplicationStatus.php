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

	/** Перемещено в корзину администратором (восстанавливаемо) */
	case Trash = 'trash';

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
			self::PendingParent  => in_array( $next, [ self::ReadyForReview, self::Expired, self::Trash ], true ),
			self::ReadyForReview => in_array( $next, [ self::Enrolling, self::Rejected, self::Expired, self::Trash ], true ),
			self::Enrolling      => in_array( $next, [ self::Converted, self::ReadyForReview ], true ),
			self::Rejected       => self::Trash === $next,
			self::Expired        => self::Trash === $next,
			// Trash восстанавливается в PendingParent или ReadyForReview
			self::Trash          => in_array( $next, [ self::PendingParent, self::ReadyForReview ], true ),
			self::Converted      => false,
		};
	}

	/**
	 * Можно ли переместить заявку в корзину.
	 *
	 * @return bool
	 */
	public function isTrashable(): bool {
		return in_array( $this, [ self::PendingParent, self::ReadyForReview, self::Rejected, self::Expired ], true );
	}
}
