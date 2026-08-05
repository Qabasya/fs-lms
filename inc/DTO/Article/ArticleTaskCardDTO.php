<?php

declare( strict_types=1 );

namespace Inc\DTO\Article;

/**
 * Class ArticleTaskCardDTO
 *
 * Карточка задания, встроенная в текст статьи.
 *
 * Появляется на месте абзаца-ссылки на задание: пост-обработка контента
 * узнаёт такой абзац и подставляет вместо него карточку. Ответ задания сюда
 * не попадает — его смотрят на самой странице задания.
 *
 * @package Inc\DTO\Article
 */
readonly class ArticleTaskCardDTO {

	/**
	 * @param int    $id        ID записи задания (нужен для уникальных id в разметке).
	 * @param string $title     Заголовок задания.
	 * @param string $url       Ссылка на страницу задания.
	 * @param string $peek      Первые строки условия без разметки (свёрнутое состояние).
	 * @param string $condition Условие задания в HTML (раскрытое состояние).
	 */
	public function __construct(
		public int $id,
		public string $title,
		public string $url,
		public string $peek,
		public string $condition,
	) {}
}