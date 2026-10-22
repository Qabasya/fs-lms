<?php

declare( strict_types=1 );

namespace Inc\Repositories\OptionsRepositories;

use Inc\Enums\Settings\OptionName;

/**
 * Состояние архива журналов: что вошло в последний скачанный архив и очищено ли это.
 *
 * Форма: `['cutoff' => 'Y-m-d H:i:s', 'exported_at' => 'Y-m-d H:i:s', 'user_id' => int,
 * 'channels' => [channel => ['count' => int, 'max_id' => int]], 'purged_at' => ?string]`.
 * Очистка разрешена только по этому состоянию: удаляется ровно то, что попало в архив.
 */
readonly class LogArchiveStateRepository {

	/**
	 * @return array<string, mixed>|null null — архив ещё не скачивали
	 */
	public function get(): ?array {
		$stored = get_option( OptionName::LogArchiveState->value, null );

		return is_array( $stored ) && ! empty( $stored['channels'] ) ? $stored : null;
	}

	/**
	 * @param array<string, mixed> $state
	 */
	public function set( array $state ): void {
		update_option( OptionName::LogArchiveState->value, $state, false );
	}
}
