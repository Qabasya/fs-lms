<?php

namespace Inc\Managers;

/**
 * Class SettingsManager
 *
 * Низкоуровневый менеджер для регистрации настроек через WordPress Settings API.
 * Инкапсулирует вызовы WordPress API: register_setting(), add_settings_section(), add_settings_field().
 * Не содержит бизнес-логики, только техническую реализацию регистрации.
 *
 * @package Inc\Managers
 */
class SettingsManager {
	/**
	 * Регистрирует настройки, секции и поля в WordPress.
	 *
	 * Принимает массивы конфигураций и регистрирует все компоненты через хук admin_init.
	 * Если массив settings пуст, регистрация не выполняется.
	 *
	 * @param array<int, array{
	 *     option_group: string,
	 *     option_name: string,
	 *     callback?: callable|null
	 * }> $settings Конфигурация опций настроек
	 *
	 * @param array<int, array{
	 *     id: string,
	 *     title: string,
	 *     callback?: callable|string,
	 *     page: string
	 * }> $sections Конфигурация секций настроек
	 *
	 * @param array<int, array{
	 *     id: string,
	 *     title: string,
	 *     callback?: callable|string,
	 *     page: string,
	 *     section: string,
	 *     args?: array<string, mixed>|string
	 * }> $fields Конфигурация полей настроек
	 *
	 * @return void
	 */
	public function register( array $settings, array $sections, array $fields ): void {
		// Если нет опций для регистрации — выходим
		if ( empty( $settings ) ) {
			return;
		}

		// Регистрируем все компоненты на хуке admin_init
		add_action( 'admin_init', function () use ( $settings, $sections, $fields ) {
			// Регистрация опций (настроек)
			foreach ( $settings as $setting ) {
				register_setting(
					$setting["option_group"],               // Группа настроек
					$setting["option_name"],                // Имя опции в БД
					$setting["callback"] ?? ''              // Коллбек санитизации (если есть)
				);
			}

			// Регистрация секций настроек
			foreach ( $sections as $section ) {
				add_settings_section(
					$section["id"],                         // Уникальный ID секции
					$section["title"],                      // Заголовок секции
					$section["callback"] ?? '',             // Коллбек для вывода описания секции
					$section["page"]                        // Страница, на которой отображается секция
				);
			}

			// Регистрация полей настроек
			foreach ( $fields as $field ) {
				add_settings_field(
					$field["id"],                           // Уникальный ID поля
					$field["title"],                        // Заголовок поля
					$field["callback"] ?? '',               // Коллбек для отрисовки поля
					$field["page"],                         // Страница настройки
					$field["section"],                      // ID секции, к которой принадлежит поле
					$field["args"] ?? ''                    // Дополнительные аргументы для коллбека
				);
			}
		} );
	}
}