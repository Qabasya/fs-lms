<?php

declare( strict_types=1 );

namespace Inc\DTO\Import;

/**
 * Результат провизии WP-учётки ({@see \Inc\Services\Enrollment\AccountProvisioningService}).
 *
 * `created` различает «учётка создана в этом вызове» от «найдена и переиспользована
 * существующая» — нужно вызывающему коду для отчёта импорта.
 */
readonly class AccountCredentialsDTO {

	public function __construct(
		public int    $userId,
		public string $login,
		public string $password,
		public bool   $created,
	) {}
}
