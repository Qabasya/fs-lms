<?php

declare( strict_types=1 );

namespace Inc\DTO\Log;

use Inc\Enums\Auth\LoginFailReason;

/**
 * Class LoginDiagnosticsDTO
 *
 * Итог разбора неудачного входа: причина и подробности для журнала.
 *
 * @package Inc\DTO\Log
 */
readonly class LoginDiagnosticsDTO {

	/**
	 * @param LoginFailReason      $reason  Причина отказа
	 * @param array<string, mixed> $details Подробности (ключи — см. {@see AuthLogDTO::detailLines()})
	 */
	public function __construct(
		public LoginFailReason $reason,
		public array $details,
	) {}
}
