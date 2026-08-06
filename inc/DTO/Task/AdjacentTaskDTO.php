<?php

declare( strict_types=1 );

namespace Inc\DTO\Task;

/**
 * Class AdjacentTaskDTO
 *
 * Соседнее задание в блоке навигации «Предыдущее / Следующее».
 *
 * @package Inc\DTO\Task
 */
readonly class AdjacentTaskDTO {

	/**
	 * @param string $title Заголовок задания.
	 * @param string $url   Пермалинк задания.
	 * @param string $slug  Слаг задания (раскодированный).
	 */
	public function __construct(
		public string $title,
		public string $url,
		public string $slug,
	) {}
}