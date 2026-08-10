<?php

declare( strict_types=1 );

namespace Inc\DTO\Article;

/**
 * Class ArticleSlugChangeDTO
 *
 * Одна строка плана пакетного переименования слагов статей.
 *
 * Собирается {@see \Inc\Services\Subject\ArticleSlugPlanner}, показывается и
 * применяется WP-CLI командой `wp fs-lms article reslug`.
 *
 * @package Inc\DTO\Article
 */
readonly class ArticleSlugChangeDTO {

	/**
	 * @param int      $post_id     ID статьи.
	 * @param string   $title       Заголовок статьи (для читаемого вывода).
	 * @param string   $old_slug    Текущий слаг.
	 * @param string   $new_slug    Слаг по действующему правилу.
	 * @param string   $status      Статус записи.
	 * @param int|null $task_number Номер задания; null — статья без задания.
	 * @param int      $ordinal     Порядковый номер статьи в серии, от 1.
	 */
	public function __construct(
		public int $post_id,
		public string $title,
		public string $old_slug,
		public string $new_slug,
		public string $status,
		public ?int $task_number,
		public int $ordinal,
	) {}

	/**
	 * Меняется ли слаг на самом деле.
	 *
	 * @return bool
	 */
	public function isChange(): bool {
		return $this->old_slug !== $this->new_slug;
	}

	/**
	 * Строка для табличного вывода WP-CLI.
	 *
	 * @return array<string, string|int>
	 */
	public function toRow(): array {
		return array(
			'ID'       => $this->post_id,
			'статус'   => $this->status,
			'задание'  => $this->task_number ?? '—',
			'было'     => $this->old_slug,
			'станет'   => $this->new_slug,
			'заголовок' => $this->title,
		);
	}
}