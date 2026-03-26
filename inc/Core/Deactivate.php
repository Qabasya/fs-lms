<?php

	namespace Inc\Core;

	/**
	 * Class Deactivate
	 *
	 * Обработчик события деактивации плагина.
	 *
	 * Вызывается при деактивации плагина через WordPress admin.
	 * Содержит все необходимые операции для корректного отключения плагина:
	 * - Сброс правил перезаписи (flush rewrite rules)
	 * - Очистка временных данных
	 * - Отмена scheduled events (wp_schedule_event)
	 *
	 * @package Inc\Core
	 *
	 * @example
	 * // Регистрация в главном файле плагина
	 * register_deactivation_hook(__FILE__, [Deactivate::class, 'deactivate']);
	 */
	class Deactivate
	{
		/**
		 * Выполняет действия при деактивации плагина.
		 *
		 * Сбрасывает правила перезаписи WordPress, чтобы удалить
		 * правила для пользовательских типов записей (CPT),
		 * зарегистрированных плагином.
		 *
		 * @return void
		 */
		public static function deactivate(): void
		{
			flush_rewrite_rules();
		}
	}