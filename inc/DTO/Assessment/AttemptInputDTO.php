<?php

declare( strict_types=1 );

namespace Inc\DTO\Assessment;

use Inc\Enums\Assessment\AttemptStatus;

readonly class AttemptInputDTO {

	public function __construct(
		public int           $assessmentId,
		public int           $studentPersonId,
		public ?int          $groupId,
		public int           $attemptNumber,
		public string        $startedAt,
		public string        $deadlineAt,
		public AttemptStatus $status = AttemptStatus::InProgress,
	) {}
}
