<?php

declare( strict_types=1 );

/**
 * Кнопка «Дублировать задание» в блоке «Опубликовать» редактора задания.
 * Выводится через хук post_submitbox_misc_actions в
 * BankRowActionsController::renderCloneTaskButton(); клик обрабатывает
 * `admin/services/content-clone.js` по контракту `data-clone-*`.
 *
 * @var int $task_id ID редактируемого задания.
 *
 * @package Inc
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="misc-pub-section fs-lms-task-clone">
	<button
		type="button"
		class="button js-fs-clone"
		data-clone-type="task"
		data-clone-id="<?php echo (int) $task_id; ?>"
		title="<?php esc_attr_e( 'Копия с новым номером и без ответа. Копируется сохранённая версия задания.', 'fs-lms' ); ?>"
	><?php esc_html_e( 'Дублировать задание', 'fs-lms' ); ?></button>
</div>
