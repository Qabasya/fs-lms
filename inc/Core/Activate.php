<?php

	namespace Inc\Core;

	/**
	 * Class Activate
	 *
	 * Обработчик события активации плагина.
	 *
	 * Вызывается при активации плагина через WordPress admin.
	 * Содержит все необходимые операции для корректного запуска плагина:
	 * - Сброс правил перезаписи (flush rewrite rules)
	 * - Создание таблиц базы данных
	 * - Установка значений по умолчанию
	 * - Инициализация опций
	 *
	 * @package Inc\Core
	 *
	 * @example
	 * // Регистрация в главном файле плагина
	 * register_activation_hook(__FILE__, [Activate::class, 'activate']);
	 */
	class Activate
	{
		/**
		 * Выполняет действия при активации плагина.
		 *
		 * Сбрасывает правила перезаписи WordPress, чтобы пользовательские
		 * типы записей (CPT) и таксономии корректно работали с ЧПУ.
		 *
		 * @return void
		 */
		public static function activate(): void
		{
			flush_rewrite_rules();
		}
	}