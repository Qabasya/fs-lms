<?php

declare( strict_types=1 );

namespace Inc\Enums\Course;

enum SubmissionStatus: string {

	case Assigned      = 'assigned';
	case Submitted     = 'submitted';
	case PendingReview = 'pending_review';
	case Graded        = 'graded';
	case Returned      = 'returned';

	public function label(): string {
		return match ( $this ) {
			self::Assigned      => 'Выдано',
			self::Submitted     => 'Сдано',
			self::PendingReview => 'На проверке',
			self::Graded        => 'Проверено',
			self::Returned      => 'Возвращено',
		};
	}

	public function isTerminal(): bool {
		return self::Graded === $this;
	}
}
