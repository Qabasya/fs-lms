<?php

declare( strict_types=1 );

namespace Inc\Enums\Exam;

/** Вычисляемое состояние работы в представлении сотрудника; значения ответа сохранены. */
enum ExamResultStatus: string {
	case None = 'none';
	case PendingReview = 'pending_review';
	case Ready = 'ready';
	case Approved = 'approved';

	public function label( ExamAudience $audience ): string {
		return match ( $this ) {
			self::None => 'Нет работы',
			self::PendingReview => 'Ожидает проверки',
			self::Ready => 'Готова к утверждению',
			self::Approved => ExamAudience::Guest === $audience ? 'Результат выдан' : 'Утверждена',
		};
	}
}
