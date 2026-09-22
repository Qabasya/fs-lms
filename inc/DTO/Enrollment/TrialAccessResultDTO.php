<?php

declare( strict_types=1 );

namespace Inc\DTO\Enrollment;

/**
 * Результат выдачи временного доступа ({@see \Inc\Services\Enrollment\TrialAccessService::grant()}).
 */
readonly class TrialAccessResultDTO {

	public function __construct(
		public int    $recordId,
		public string $login,
		public string $groupName,
		public string $expiresAt,
	) {}

	public function toArray(): array {
		return array(
			'record_id'  => $this->recordId,
			'login'      => $this->login,
			'group_name' => $this->groupName,
			'expires_at' => $this->expiresAt,
		);
	}
}
