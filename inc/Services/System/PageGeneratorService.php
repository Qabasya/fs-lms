<?php

namespace Inc\Services\System;

use Inc\Enums\Wp\PageRoutes;

/**
 * Class PageGeneratorService
 *
 * Сервис для автоматического создания служебных страниц плагина.
 *
 * @package Inc\Services
 *
 * ### Основные обязанности:
 *
 * 1. **Проверка существования страницы** — поиск страницы по slug.
 * 2. **Создание страницы** — вставка новой страницы с заданными параметрами.
 * 3. **Восстановление страницы** — возврат в publish страницы из черновика/корзины.
 *
 * ### Архитектурная роль:
 *
 * Используется при активации плагина для создания страниц входа, регистрации и профиля.
 * Изолирует логику работы с WordPress-функциями (get_page_by_path, wp_insert_post).
 */
class PageGeneratorService {

	/**
	 * Создаёт страницу плагина, если она ещё не существует.
	 *
	 * @param PageRoutes $route    Enum с маршрутом (slug страницы)
	 * @param string     $title    Заголовок страницы
	 * @param string     $shortcode Шорткод для вставки в содержимое страницы
	 *
	 * @return void
	 */
	public function createPageIfNeeded( PageRoutes $route, string $title, string $shortcode ): void {
		// get_page_by_path() — WordPress-функция для поиска страницы по slug
		// Возвращает объект WP_Post или null, если страница не найдена
		$page_exists = get_page_by_path( $route->value );

		// Если страница не существует — создаём её
		if ( ! $page_exists ) {
			// wp_insert_post() — создаёт новый пост/страницу в базе данных
			wp_insert_post(
				array(
					'post_title'   => $title,                 // Заголовок страницы
					'post_content' => $shortcode,             // Содержимое (шорткод)
					'post_status'  => 'publish',              // Статус: опубликована
					'post_type'    => 'page',                 // Тип поста: страница
					'post_name'    => $route->value,          // Slug (ЧПУ)
				)
			);
		}
	}

	/**
	 * Гарантирует, что страница существует и опубликована.
	 *
	 * Recovery-вариант createPageIfNeeded(): дополнительно возвращает в publish
	 * страницу, которая существует, но находится в черновике/ожидании. Контент
	 * шорткода дописывается только если он пуст — ручные правки не затираются.
	 *
	 * Корзина: WordPress дописывает к слагу суффикс (`{slug}__trashed`), поэтому
	 * get_page_by_path() такую страницу не возвращает — в этом случае создаётся
	 * свежая опубликованная (как при восстановлении страницы согласия).
	 *
	 * @param PageRoutes $route     Enum с маршрутом (slug страницы)
	 * @param string     $title     Заголовок страницы
	 * @param string     $shortcode Шорткод для вставки в содержимое страницы
	 *
	 * @return void
	 */
	public function ensurePublished( PageRoutes $route, string $title, string $shortcode ): void {
		$page = get_page_by_path( $route->value );

		if ( $page instanceof \WP_Post ) {
			// Уже опубликована — всё в порядке.
			if ( 'publish' === $page->post_status ) {
				return;
			}

			// Черновик/ожидание (slug не изменён) — возвращаем в publish.
			// wp_update_post() — обновляет существующую запись.
			wp_update_post(
				array(
					'ID'           => $page->ID,
					'post_status'  => 'publish',
					'post_content' => $page->post_content ?: $shortcode,
				)
			);

			return;
		}

		// Страницы нет в живых статусах (отсутствует или в корзине) — создаём заново.
		$this->createPageIfNeeded( $route, $title, $shortcode );
	}
}