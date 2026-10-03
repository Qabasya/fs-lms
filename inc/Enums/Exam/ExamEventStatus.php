<?php declare( strict_types=1 );

namespace Inc\Enums\Exam;

enum ExamEventStatus: string {
	case Draft = 'draft';
	case Published = 'published';
	case Completed = 'completed';
	case Cancelled = 'cancelled';

	public function label(): string {
		return match ( $this ) {
			self::Draft => 'Черновик',
			self::Published => 'Опубликовано',
			self::Completed => 'Завершено',
			self::Cancelled => 'Отменено',
		};
	}

	public function isEditable(): bool {
		return self::Draft === $this || self::Published === $this;
	}

	public function acceptsRegistration(): bool {
		return self::Published === $this;
	}
}
