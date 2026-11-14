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

	/** @return list<string> Статусы сдач, ожидающих проверки. */
	public static function pendingReviewValues(): array {
		return array( self::Submitted->value, self::PendingReview->value );
	}

	public function isTerminal(): bool {
		return self::Graded === $this;
	}
}
