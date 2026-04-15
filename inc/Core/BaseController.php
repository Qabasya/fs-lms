<?php

namespace Inc\Core;

/**
 * Class BaseController
 *
 * Базовый класс для контроллеров, коллбеков и других сервисов,
 * которым нужны пути/URL плагина.
 *
 * Наследует все константы из PluginConfig (slugs, capabilities,
 * option names и т.д.), предоставляя к ним доступ через self::
 * во всех классах-наследниках.
 *
 * Репозитории и классы без зависимости от WP-путей используют
 * PluginConfig напрямую и не наследуют BaseController.
 *
 * @package Inc\Core
 */
class BaseController {

	/**
	 * Абсолютный путь к директории плагина.
	 *
	 * @var string
	 */
	public string $plugin_path;

	/**
	 * URL директории плагина.
	 *
	 * @var string
	 */
	public string $plugin_url;

	/**
	 * Базовое имя плагина (plugin_basename).
	 *
	 * @var string
	 */
	public string $plugin_name;

	/**
	 * Версия плагина.
	 *
	 * @var string
	 */
	public string $plugin_version = '0.0.1';

	/**
	 * Конструктор.
	 *
	 * Инициализирует пути и URL плагина.
	 * Вызывается автоматически при создании экземпляра через DI-контейнер.
	 */
	public function __construct() {
		// Путь в корень плагина (на уровень выше папки Core)
		$root_path = dirname( __FILE__, 2 );

		// Устанавливаем абсолютный путь к папке плагина
		$this->plugin_path = plugin_dir_path( $root_path );

		// Устанавливаем URL папки плагина
		$this->plugin_url = plugin_dir_url( $root_path );

		// Устанавливаем базовое имя плагина (например, "fs-lms/fs-lms.php")
		$this->plugin_name = plugin_basename( $root_path );
	}

	/**
	 * Возвращает полный путь к файлу внутри плагина.
	 *
	 * @param string $path Относительный путь (например, 'templates/admin.php')
	 *
	 * @return string Абсолютный путь к файлу
	 *
	 * @example
	 * $this->path('templates/admin.php');
	 * // Результат: /var/www/wp-content/plugins/fs-lms/templates/admin.php
	 */
	public function path( string $path = '' ): string {
		return $this->plugin_path . ltrim( $path, '/\\' );
	}

	/**
	 * Возвращает URL к файлу внутри плагина.
	 *
	 * @param string $path Относительный путь (например, 'assets/css/style.css')
	 *
	 * @return string URL к файлу
	 *
	 * @example
	 * $this->url('assets/css/style.css');
	 * // Результат: https://example.com/wp-content/plugins/fs-lms/assets/css/style.css
	 */
	public function url( string $path = '' ): string {
		return $this->plugin_url . ltrim( $path, '/' );
	}

	/**
	 * Возвращает базовое имя плагина.
	 *
	 * @return string Базовое имя плагина (fs-lms/fs-lms.php)
	 */
	public function pluginName(): string {
		return $this->plugin_name;
	}
}