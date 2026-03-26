<?php

	namespace Inc\Shared\Traits;

	/**
	 * Trait TemplateRenderer
	 *
	 * Трейт для рендеринга PHP-шаблонов в административной панели.
	 *
	 * Обеспечивает единый механизм загрузки шаблонов с передачей
	 * переменных через extract(). Используется в классах-коллбеках
	 * для отображения страниц админ-панели.
	 *
	 * @package Inc\Shared\Traits
	 */
	trait TemplateRenderer
	{
		/**
		 * Загружает и отображает PHP-шаблон с переданными данными.
		 *
		 * Преобразует массив $args в переменные, доступные в шаблоне,
		 * используя функцию extract().
		 *
		 * @param string $template_name Имя файла шаблона (без расширения .php)
		 * @param array<string, mixed> $args Ассоциативный массив данных для передачи в шаблон
		 *
		 * @return void
		 *
		 * @example
		 * $this->render('admin-dashboard', ['subjects' => $subjects]);
		 * // В шаблоне будет доступна переменная $subjects
		 */
		protected function render(string $template_name, array $args = []): void
		{
			$file = $this->path("templates/{$template_name}.php");

			if (!file_exists($file)) {
				echo "";
				return;
			}

			if (!empty($args)) {
				extract($args);
			}

			require_once $file;
		}
	}