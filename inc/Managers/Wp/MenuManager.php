<?php

namespace Inc\Managers\Wp;

/**
 * Class MenuManager
 *
 * Менеджер регистрации административного меню.
 *
 * Инкапсулирует вызовы WordPress API для создания страниц меню.
 * Принимает массивы конфигурации и регистрирует все пункты меню
 * через хук admin_menu.
 *
 * Не содержит бизнес-логики, только техническую реализацию регистрации.
 *
 * @package Inc\Managers
 */
class MenuManager {
	/**
	 * Регистрирует страницы и подстраницы административного меню.
	 *
	 * Метод оборачивает вызовы WordPress API в хук admin_menu.
	 * Если массив pages пуст, регистрация не выполняется.
	 *
	 * @param array<int, array{
	 *     page_title: string,
	 *     menu_title: string,
	 *     capability: string,
	 *     menu_slug: string,
	 *     callback: callable,
	 *     icon_url: string,
	 *     position: int
	 * }> $pages    Конфигурация главных страниц меню
	 *
	 * @param array<int, array{
	 *     parent_slug: string,
	 *     page_title: string,
	 *     menu_title: string,
	 *     capability: string,
	 *     menu_slug: string,
	 *     callback: callable
	 * }> $subpages Конфигурация подстраниц меню
	 *
	 * @return void
	 */
	public function register( array $pages, array $subpages ): void {
		if ( empty( $pages ) && empty( $subpages ) ) {
			return;
		}

		$callback = function () use ( $pages, $subpages ) {
			// Регистрация главных страниц меню
			foreach ( $pages as $page ) {
				add_menu_page(
					$page['page_title'],
					$page['menu_title'],
					$page['capability'],
					$page['menu_slug'],
					$page['callback'],
					$page['icon_url'],
					$page['position']
				);
			}

			// Регистрация подстраниц меню
			foreach ( $subpages as $subpage ) {
				add_submenu_page(
					$subpage['parent_slug'],
					$subpage['page_title'],
					$subpage['menu_title'],
					$subpage['capability'],
					$subpage['menu_slug'],
					$subpage['callback']
				);
			}
		};

		// Если admin_menu уже выполняется (или выполнен) — регистрируем сразу,
		// иначе отложенный add_action не сработал бы. Нужно для динамических
		// пунктов меню, которые сами строятся внутри admin_menu (зависят от
		// текущего пользователя, недоступного на момент загрузки плагина).
		if ( doing_action( 'admin_menu' ) || did_action( 'admin_menu' ) ) {
			$callback();

			return;
		}

		add_action( 'admin_menu', $callback );
	}
}
