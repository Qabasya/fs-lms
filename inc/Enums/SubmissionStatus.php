<?php

declare( strict_types=1 );

namespace Inc\Enums;

enum SubmissionStatus: string {

	case Assigned  = 'assigned';
	case Submitted = 'submitted';
	case Graded    = 'graded';
	case Returned  = 'returned';

	public function label(): string {
		return match ( $this ) {
			self::Assigned  => 'Выдано',
			self::Submitted => 'Сдано',
			self::Graded    => 'Проверено',
			self::Returned  => 'Возвращено',
		};
	}

	public function isTerminal(): bool {
		return self::Graded === $this;
	}
}
