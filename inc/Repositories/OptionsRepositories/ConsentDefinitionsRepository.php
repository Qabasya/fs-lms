<?php

declare( strict_types=1 );

namespace Inc\Repositories\OptionsRepositories;

use Inc\Enums\OptionName;

/**
 * Хранит определения согласий: [key => ['name' => string, 'page_id' => int]].
 * Каждое согласие имеет отдельную WP-страницу; история — WP-ревизии этой страницы.
 */
class ConsentDefinitionsRepository {

	public function readAll(): array {
		return (array) get_option( OptionName::ConsentDefinitions->value, array() );
	}

	public function findByKey( string $key ): ?array {
		return $this->readAll()[ $key ] ?? null;
	}

	public function save( string $key, string $name, int $pageId ): void {
		$defs         = $this->readAll();
		$defs[ $key ] = array(
			'name'    => $name,
			'page_id' => $pageId,
		);
		update_option( OptionName::ConsentDefinitions->value, $defs );
	}

	public function delete( string $key ): void {
		$defs = $this->readAll();
		unset( $defs[ $key ] );
		update_option( OptionName::ConsentDefinitions->value, $defs );
	}
}
