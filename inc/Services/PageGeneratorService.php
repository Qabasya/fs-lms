<?php

namespace Inc\Services;

use Inc\Enums\PageRoutes;

class PageGeneratorService {
	/**
	 * Создать страницу плагина, если она еще не создана
	 */
	public function createPageIfNeeded( PageRoutes $route, string $title, string $shortcode ): void {
		// Проверяем существование по слагу (значению enum)
		$page_exists = get_page_by_path( $route->value );

		if ( ! $page_exists ) {
			wp_insert_post(
				array(
					'post_title'   => $title,
					'post_content' => $shortcode,
					'post_status'  => 'publish',
					'post_type'    => 'page',
					'post_name'    => $route->value,
				)
			);
		}
	}
}
