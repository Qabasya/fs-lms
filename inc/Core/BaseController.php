<?php

namespace Inc\Core;

use Inc\Shared\Traits\AjaxResponse;

/**
 * Class BaseController
 *
 * Базовый класс для контроллеров, коллбеков и других сервисов.
 *
 * @package Inc\Core
 *
 * ### Основные обязанности:
 *
 * 1. **Хранение путей плагина** — предоставляет доступ к абсолютному пути и URL директории плагина.
 * 2. **Утилитарные методы** — методы path() и url() для построения полных путей к файлам.
 * 3. **Трейт AjaxResponse** — добавляет методы для отправки JSON-ответов (success, error, respond).
 *
 * ### Архитектурная роль:
 *
 * Является родительским классом для большинства сервисов плагина (контроллеры, коллбеки),
 * предоставляя им доступ к файловой системе плагина и AJAX-функционалу.
 * Репозитории и классы без зависимости от WP-путей используют PluginConfig напрямую.
 */
class BaseController {
	use AjaxResponse;  // Трейт с методами success(), error(), respond() для AJAX
	
	/**
	 * Абсолютный путь к директории плагина.
	 * Пример: /var/www/wp-content/plugins/fs-lms/
	 *
	 * @var string
	 */
	public string $plugin_path;
	
	/**
	 * URL директории плагина.
	 * Пример: https://example.com/wp-content/plugins/fs-lms/
	 *
	 * @var string
	 */
	public string $plugin_url;
	
	/**
	 * Базовое имя плагина (plugin_basename).
	 * Пример: fs-lms/fs-lms.php
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
		// dirname(, 2) — возвращает родительскую директорию на 2 уровня выше
		// Из Inc/Core/BaseController.php → Inc/Core → Inc → корень плагина
		$root_path = dirname( __FILE__, 2 );
		
		// plugin_dir_path() — возвращает путь к директории плагина с завершающим слешем
		$this->plugin_path = plugin_dir_path( $root_path );
		
		// plugin_dir_url() — возвращает URL директории плагина с завершающим слешем
		$this->plugin_url = plugin_dir_url( $root_path );
		
		// plugin_basename() — возвращает относительный путь от папки wp-content/plugins
		$this->plugin_name = plugin_basename( $root_path );
	}
	
	/**
	 * Возвращает полный путь к файлу внутри плагина.
	 *
	 * @param string $path Относительный путь (например, 'templates/admin.php')
	 *
	 * @return string Абсолютный путь к файлу
	 */
	public function path( string $path = '' ): string {
		// ltrim() — удаляет слеши в начале пути (если они есть)
		return $this->plugin_path . ltrim( $path, '/\\' );
	}
	
	/**
	 * Возвращает URL к файлу внутри плагина.
	 *
	 * @param string $path Относительный путь (например, 'assets/css/style.css')
	 *
	 * @return string URL к файлу
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