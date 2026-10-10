<?php

declare( strict_types=1 );

namespace Inc\Services\System;

use Inc\Enums\Wp\PageRoutes;
use Inc\Managers\Wp\PostManager;

/** Обеспечивает служебные страницы через общий менеджер записей WordPress. */
class PageGeneratorService {

	public function __construct(
		private readonly PostManager $posts,
	) {}

	/** Создаёт отсутствующую страницу, сохраняя существующую без изменений. */
	public function createPageIfNeeded( PageRoutes $route, string $title, string $shortcode ): void {
		if ( null !== $this->posts->findByPath( $route->value ) ) {
			return;
		}

		$this->insertPage( $route, $title, $shortcode );
	}

	/**
	 * Публикует страницу, сохраняя ручное содержимое. Корзина с изменённым слагом не занимает исходный маршрут.
	 * Ошибка записи не считается успешным обеспечением страницы.
	 */
	public function ensurePublished( PageRoutes $route, string $title, string $shortcode ): void {
		$page = $this->posts->findByPath( $route->value );
		if ( null === $page ) {
			$this->insertPage( $route, $title, $shortcode );
			return;
		}

		if ( 'publish' === $page->post_status ) {
			return;
		}

		if ( ! $this->posts->update( $page->ID, array(
			'post_status'  => 'publish',
			'post_content' => $page->post_content ?: $shortcode,
		) ) ) {
			throw new \RuntimeException( 'Не удалось опубликовать служебную страницу: ' . $route->value );
		}

		$this->assertPublished( $route, $page->ID );
	}

	private function insertPage( PageRoutes $route, string $title, string $shortcode ): void {
		$id = $this->posts->insert( array(
			'post_title'   => $title,
			'post_content' => $shortcode,
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_name'    => $route->value,
		) );
		if ( $id <= 0 ) {
			throw new \RuntimeException( 'Не удалось создать служебную страницу: ' . $route->value );
		}

		$this->assertPublished( $route, $id );
	}

	/** Учитывает фильтры WordPress, способные изменить статус или адрес при сохранении. */
	private function assertPublished( PageRoutes $route, int $id ): void {
		$page = $this->posts->findByPath( $route->value );
		if ( null === $page || $page->ID !== $id || 'publish' !== $page->post_status ) {
			throw new \RuntimeException( 'Служебная страница не опубликована по ожидаемому адресу: ' . $route->value );
		}
	}
}
