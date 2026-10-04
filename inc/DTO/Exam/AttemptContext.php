<?php

declare( strict_types=1 );

namespace Inc\DTO\Exam;

use Inc\Enums\Exam\ExamAudience;

/**
 * Контекст участника для старта попытки экзамена (6.1).
 * Собирается один раз и переиспользуется для всех операций со сдачей.
 *
 * @package Inc\DTO\Exam
 */
readonly class AttemptContext {

	public function __construct(
		public ExamAudience $audience,
		public int $participationId,
		public int $registrationId,
		public ?int $personId,
		public ?int $wpUserId,
	) {}
}
