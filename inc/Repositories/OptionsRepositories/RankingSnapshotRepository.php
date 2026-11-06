<?php

declare( strict_types=1 );

namespace Inc\Repositories\OptionsRepositories;

use Inc\Enums\Settings\OptionName;

/** Один целостный снимок: направление → группа → person ID → место. */
class RankingSnapshotRepository {
	public function read(): array {
		$value = get_option( OptionName::RankingSnapshot->value, array() );
		return is_array( $value ) ? $value : array();
	}

	public function clear(): void {
		delete_option( OptionName::RankingSnapshot->value );
	}

	public function save( array $snapshot ): void {
		// Явно отключаем автозагрузку: снимок не нужен большинству страниц.
		update_option( OptionName::RankingSnapshot->value, $snapshot, false );
		if ( $this->read() !== $snapshot ) {
			throw new \RuntimeException( 'Не удалось сохранить снимок рейтинга.' );
		}
	}
}
