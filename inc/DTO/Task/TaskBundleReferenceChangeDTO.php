<?php

declare( strict_types=1 );

namespace Inc\DTO\Task;

/**
 * Class TaskBundleReferenceChangeDTO
 *
 * Одна строка плана переезда ссылок на связку (parent-id triple_task) на её
 * children — Work.itemIds или Assessment.taskIds/taskPoints/taskNumbers.
 *
 * Собирается {@see \Inc\Services\Task\TaskBundleMigrationPlanner}, показывается
 * и применяется WP-CLI командой `wp fs-lms task-bundle migrate`.
 *
 * @package Inc\DTO\Task
 */
readonly class TaskBundleReferenceChangeDTO {

	/**
	 * @param int              $post_id       ID Work/Assessment.
	 * @param string           $kind          'work' | 'assessment'.
	 * @param string           $title         Заголовок (для читаемого вывода).
	 * @param int[]             $old_item_ids  Список до замены.
	 * @param int[]             $new_item_ids  Список после замены (parent → 3 children).
	 * @param array<int, float> $new_task_points  Только для assessment; [] — не меняется.
	 * @param array<int, string> $new_task_numbers Только для assessment; [] — не меняется.
	 */
	public function __construct(
		public int    $post_id,
		public string $kind,
		public string $title,
		public array  $old_item_ids,
		public array  $new_item_ids,
		public array  $new_task_points = array(),
		public array  $new_task_numbers = array(),
	) {}

	/**
	 * Строка для табличного вывода WP-CLI.
	 *
	 * @return array<string, string|int>
	 */
	public function toRow(): array {
		return array(
			'ID'       => $this->post_id,
			'тип'      => 'work' === $this->kind ? 'работа' : 'контрольная',
			'заголовок' => $this->title,
			'было'     => implode( ',', $this->old_item_ids ),
			'станет'   => implode( ',', $this->new_item_ids ),
		);
	}
}
