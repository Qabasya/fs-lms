<?php

declare( strict_types=1 );

namespace Inc\Repositories\OptionsRepositories;

use Inc\Enums\OptionName;

class ConsentOptionsRepository {

	/**
	 * Возвращает сохранённые метаданные страницы согласия (hash + updated_at).
	 *
	 * @return array{hash: string, updated_at: string}
	 */
	public function getPageMeta(): array {
		return (array) get_option( OptionName::ConsentPageMeta->value, array() );
	}

	/**
	 * Сохраняет метаданные страницы согласия.
	 *
	 * @param array $meta Массив с ключами 'hash' и 'updated_at'
	 */
	public function savePageMeta( array $meta ): void {
		update_option( OptionName::ConsentPageMeta->value, $meta );
	}
}
