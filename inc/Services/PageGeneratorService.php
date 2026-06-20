<?php

namespace Inc\Services;

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
}