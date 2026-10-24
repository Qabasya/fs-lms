<?php
/**
 * Форма разового переноса заданий со старой версии сайта: общая для скрытой страницы
 * `fs_lms_legacy_task_import` и вкладки «Импорт» страницы предмета.
 *
 * @var \Inc\DTO\Subject\SubjectDTO[]                                      $subjects   Предметы для выбора.
 * @var array<string, array<int, array{slug: string, name: string}>>        $taxonomies Таксономии по ключу предмета.
 * @var int                                                                 $batch_size Записей в одном AJAX-запросе.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;
?>
<table class="form-table" role="presentation">
	<tbody>
	<tr>
		<th scope="row"><label for="fs-legacy-import-file">Файл переноса</label></th>
		<td>
			<input type="file" id="fs-legacy-import-file" accept=".json,application/json">
			<p class="description">JSON-массив записей; повторный запуск пропускает уже перенесённые.</p>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="fs-legacy-import-subject">Предмет</label></th>
		<td>
			<select id="fs-legacy-import-subject" data-taxonomies="<?php echo esc_attr( (string) wp_json_encode( $taxonomies ) ); ?>">
				<?php foreach ( $subjects as $subject ) : ?>
					<option value="<?php echo esc_attr( $subject->key ); ?>">
						<?php echo esc_html( $subject->name ); ?> (<?php echo esc_html( $subject->key ); ?>)
					</option>
				<?php endforeach; ?>
			</select>
		</td>
	</tr>
	<?php
	// Списки заполняет legacy-task-import.js из data-taxonomies выбранного предмета;
	// data-suffix — хвост слага, который выбирается по умолчанию ({ключ}_author и т.д.).
	$legacy_tax_fields = array(
		'author' => 'Таксономия автора',
		'year'   => 'Таксономия года',
		'level'  => 'Таксономия сложности',
	);
	?>
	<?php foreach ( $legacy_tax_fields as $suffix => $label ) : ?>
		<tr>
			<th scope="row"><label for="fs-legacy-import-<?php echo esc_attr( $suffix ); ?>-tax"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<select id="fs-legacy-import-<?php echo esc_attr( $suffix ); ?>-tax" class="js-legacy-import-tax" data-suffix="<?php echo esc_attr( $suffix ); ?>"></select>
				<p class="description">«По умолчанию» — слаг «{ключ_предмета}_<?php echo esc_html( $suffix ); ?>». Если у предмета нет выбранной таксономии, это поле при переносе пропускается.</p>
			</td>
		</tr>
	<?php endforeach; ?>
	<tr>
		<th scope="row">Повторный перенос</th>
		<td>
			<label for="fs-legacy-import-refill">
				<input type="checkbox" id="fs-legacy-import-refill">
				Обновить уже импортированные задания
			</label>
			<p class="description">Записи, чей legacy_number уже переносился, не пропускаются: условия, ответы, код и файл перезаписываются из файла. Новые задания создаются как обычно.</p>
		</td>
	</tr>
	</tbody>
</table>

<p class="fs-legacy-import__controls">
	<button type="button" id="fs-legacy-import-start" class="button button-primary" data-batch-size="<?php echo esc_attr( (string) $batch_size ); ?>">Начать перенос</button>
	<span id="fs-legacy-import-status" class="fs-legacy-import__status"></span>
</p>

<progress id="fs-legacy-import-progress" class="fs-legacy-import__progress" value="0" max="100" hidden></progress>

<div id="fs-legacy-import-report"></div>
