<?php

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Вкладка «Менеджер заданий»: массовая правка заданий предмета через CSV.
 *
 * @var \Inc\DTO\Subject\SubjectViewDTO $dto
 */
?>

<div class="task-manager-wrapper fs-task-bulk" id="fs-task-bulk" data-subject="<?php echo esc_attr( $dto->subject_key ); ?>">
	<h1 class="wp-heading-inline">Менеджер заданий</h1>
	<p class="description">
		Массовая правка заданий через CSV: номер (адрес), название, дата публикации, номер задания и таксономии.
		Файлы заданий переименовываются под новый номер, ссылки <code>http://</code> заменяются на <code>https://</code>.
	</p>

	<ol class="fs-task-bulk__steps">
		<li>Выберите номер задания (или все задания) и скачайте шаблон — в нём задания с текущими значениями.</li>
		<li>
			В колонке «Старый номер» ничего не меняйте. Заполните «Новый номер» (например, <code>1200</code>) и правьте остальное.
			Пустая ячейка — «не менять»; в таксономиях несколько значений через <code>|</code>, <code>-</code> снимает все значения.
			Название — только часть после «№ 1200. », префикс собирается сам.
		</li>
		<li>Загрузите файл и проверьте результат. Применяются только строки без ошибок; перед записью сохраняется снимок.</li>
	</ol>

	<p class="fs-task-bulk__controls">
		<label for="fs-task-bulk-type">Задания</label>
		<select id="fs-task-bulk-type">
			<option value="">Все задания предмета</option>
			<?php foreach ( $dto->task_types as $type ) : ?>
				<option value="<?php echo esc_attr( $type->slug ); ?>">
					<?php echo esc_html( $type->description ); ?> (<?php echo esc_html( (string) $type->post_count ); ?>)
				</option>
			<?php endforeach; ?>
		</select>
		<button type="button" id="fs-task-bulk-template" class="button">Скачать шаблон CSV</button>
	</p>

	<p class="fs-task-bulk__controls">
		<input type="file" id="fs-task-bulk-file" accept=".csv,text/csv">
		<button type="button" id="fs-task-bulk-preview" class="button button-primary">Проверить файл</button>
		<button type="button" id="fs-task-bulk-apply" class="button button-primary" hidden>Применить</button>
	</p>

	<div id="fs-task-bulk-report"></div>

	<h2 class="fs-task-bulk__title">Снимки</h2>
	<p class="description">
		Снимок создаётся при каждом применении: в нём состояние заданий до правки и после. По снимку можно вернуть задания
		и скачать CSV «старый номер → новые данные». Хранятся последние 30 снимков предмета.
	</p>

	<table class="wp-list-table widefat fixed striped fs-table">
		<thead>
		<tr>
			<th class="column-snapshot-id">№</th>
			<th>Дата</th>
			<th>Автор</th>
			<th>Заданий</th>
			<th>Статус</th>
			<th class="column-actions">Действия</th>
		</tr>
		</thead>
		<tbody id="fs-task-bulk-snapshots">
		<tr class="no-items"><td colspan="6">Загрузка…</td></tr>
		</tbody>
	</table>
</div>

<?php
// Модальное окно подтверждения (ConfirmModal) — на странице предмета его нет.
$fs_confirm_modal = dirname( __DIR__, 2 ) . '/modals/confirm-modal.php';
if ( file_exists( $fs_confirm_modal ) ) {
	include $fs_confirm_modal;
}
