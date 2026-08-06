<?php

declare( strict_types=1 );

namespace Inc\DTO\Task;

/**
 * Class TabDTO
 *
 * Один таб карточки задания (ответ, решение, пояснение).
 *
 * $is_code различает тип содержимого: у таба решения $content — сырой листинг,
 * который шаблон печатает в `<pre><code class="js-code">`; у остальных — HTML.
 *
 * @package Inc\DTO\Task
 */
readonly class TabDTO {

	/**
	 * @param string $id      Идентификатор таба (data-tab / data-panel).
	 * @param string $label   Подпись кнопки таба.
	 * @param string $content HTML либо сырой код — см. $is_code.
	 * @param bool   $is_code Содержимое — листинг кода.
	 * @param string $lang    Язык листинга (только при $is_code).
	 */
	public function __construct(
		public string $id,
		public string $label,
		public string $content,
		public bool $is_code = false,
		public string $lang = '',
	) {}
}