<?php

namespace Inc\Managers;

/**
 * Class TaxonomyManager
 *
 * Низкоуровневый менеджер регистрации таксономий.
 *
 * Инкапсулирует вызовы WordPress API (register_taxonomy).
 * Не содержит логики выбора предметов или типов записей,
 * только техническую реализацию регистрации через хук init.
 *
 * @package Inc\Managers
 */
class TaxonomyManager {
	/**
	 * Регистрирует накопленные конфигурации таксономий.
	 *
	 * @param array<string, array{
	 * post_types: string|array<int, string>,
	 * args: array<string, mixed>
	 * }> $taxonomies Массив конфигураций ['slug' => ['post_types' => ..., 'args' => ...]]
	 *
	 * @return void
	 */
	public function register( array $taxonomies ): void {
		if ( empty( $taxonomies ) ) {
			return;
		}

		// Таксономии, как и CPT, должны регистрироваться на хуке init
		add_action( 'init', function () use ( $taxonomies ) {
			foreach ( $taxonomies as $slug => $data ) {
				register_taxonomy(
					$slug,
					$data['post_types'],
					$data['args']
				);
			}
		} );
	}
}