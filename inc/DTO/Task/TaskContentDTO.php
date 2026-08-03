<?php

declare( strict_types=1 );

namespace Inc\DTO\Task;

/**
 * Class TaskContentDTO
 *
 * Содержимое задания из fs_lms_meta, подготовленное для шаблона.
 *
 * Код решения хранится СЫРЫМ (без разметки): подсветку и обвязку редактора
 * строит фронтенд (`components/code-block.js` по хуку `.js-code`), шаблон лишь
 * печатает его в `<pre><code>` с экранированием.
 *
 * @package Inc\DTO\Task
 */
readonly class TaskContentDTO {

	/**
	 * @param string $condition Условие задания (HTML, прошло the_content).
	 * @param string $answer    Правильный ответ.
	 * @param string $code      Листинг решения — сырой текст, без HTML.
	 * @param string $code_lang Язык листинга для бейджа редактора.
	 * @param string $text      Пояснение/разбор (HTML).
	 */
	public function __construct(
		public string $condition = '',
		public string $answer = '',
		public string $code = '',
		public string $code_lang = '',
		public string $text = '',
	) {}
}