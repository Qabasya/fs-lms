<?php

declare( strict_types=1 );

namespace Inc\Enums\Assessment;

enum AttemptStatus: string {
	case InProgress = 'in_progress';
	case Submitted  = 'submitted';
	case Graded     = 'graded';
	case Expired    = 'expired';

	public function isTerminal(): bool {
		return match ( $this ) {
			self::Graded, self::Expired => true,
			default                     => false,
		};
	}
}
