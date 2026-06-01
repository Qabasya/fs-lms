<?php

declare( strict_types=1 );

namespace Inc\DTO;

/**
 * Class EnrollmentResultDTO
 *
 * Результат зачисления.
 *
 * @package Inc\DTO
 *
 * ### Примечания:
 *
 * - Логины и пароли заполнены всегда (и при auto-email, и при ручном режиме).
 * - partialFailure = true, если транзакция прошла, но post-effects не завершились —
 *   recovery job подберёт через 15 минут; в этом случае credentials = null.
 */
readonly class EnrollmentResultDTO {

	public function __construct(
		public int     $enrollmentId,
		public int     $studentUserId,
		public int     $guardianUserId,
		public ?string $studentLogin,
		public ?string $studentPassword,
		public ?string $guardianLogin,
		public ?string $guardianPassword,
		public bool    $partialFailure = false,
	) {}
}
