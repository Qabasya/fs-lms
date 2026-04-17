<?php

namespace Inc\Managers;

/**
 * Class CPTManager
 *
 * Менеджер регистрации пользовательских типов записей (CPT) для предметов.
 *
 * Инкапсулирует вызовы WordPress API для создания CPT.
 * Принимает массив конфигураций и регистрирует все типы записей
 * через хук init.
 *
 * Не содержит бизнес-логики, только техническую реализацию регистрации.
 *
 * @package Inc\Managers
 */
class CPTManager {
	/**
	 * Регистрирует переданные типы записей в WordPress.
	 *
	 * Метод оборачивает вызовы register_post_type() в хук init.
	 * Если массив post_types пуст, регистрация не выполняется.
	 *
	 * @param array<string, array> $post_types Массив конфигураций CPT,
	 *                                         где ключ — slug типа записи,
	 *                                         значение — аргументы для register_post_type()
	 *
	 * @return void
	 *
	 * @example
	 * $manager->register([
	 *     'math_tasks' => [
	 *         'labels' => ['name' => 'Задания', 'singular_name' => 'Задание'],
	 *         'public' => true,
	 *         'show_in_menu' => false
	 *     ]
	 * ]);
	 */
	public function register( array $post_types ): void {
		if ( empty( $post_types ) ) {
			return;
		}

		add_action(
			'init',
			function () use ( $post_types ) {
				foreach ( $post_types as $slug => $args ) {
					register_post_type( $slug, $args );
				}
			}
		);
	}
}
