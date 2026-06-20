<?php

declare( strict_types=1 );

use Inc\DTO\Subject\SubjectDTO;
use Inc\Enums\Import\ImportColumn;

defined( 'ABSPATH' ) || exit;

/**
 * Таб «Импорт» — загрузка учеников прошлых лет из CSV в выбранные предмет и период.
 *
 * @var SubjectDTO[] $subjects         Список предметов
 * @var array                         $academic_periods Список учебных периодов
 */
?>

<div id="tab-import" class="tab-pane active fs-lms-import">

	<div class="header-row">
		<h1 class="wp-heading-inline">Импорт учеников из CSV</h1>
	</div>

	<p class="description">
		Одна строка файла = ученик + родитель + группа + договор. Группы, ученики, родители
		и записи о зачислении создаются автоматически. Предмет и период выбираются ниже и
		применяются ко всем строкам. Учётные записи WP при импорте не создаются.
	</p>
	<p class="description">
		Колонки отчисления необязательны: если они заполнены, запись создаётся в архиве.
        Жёстко заданы следующие значения колонок:
        <ol>
        <li>Тип документа: <code>Паспорт</code> или <code>Свидетельство о рождении</code></li>
        <li>Причина отчисления: <code>Окончание курса</code>, <code>Перевод</code>, <code>По собственному желанию</code>, все остальные добавляются в причину «Другое»</li>
        </ol>
	</p>

	<p>
		<button
			type="button"
			class="button"
			id="fs-import-template"
            data-headers="<?php echo esc_attr( implode( ';', ImportColumn::headers() ) ); ?>"
            data-examples="<?php echo esc_attr( (string) wp_json_encode( ImportColumn::exampleRows(), JSON_UNESCAPED_UNICODE ) ); ?>">

            <span class="dashicons dashicons-download"></span>
			Скачать шаблон CSV
		</button>
	</p>

	<?php if ( empty( $subjects ) || empty( $academic_periods ) ) : ?>

		<div class="notice notice-warning inline">
			<p>Для импорта нужен хотя бы один предмет и один учебный период. Создайте их на вкладках «Предметы» и «Периоды».</p>
		</div>

	<?php else : ?>

		<form id="fs-lms-import-form" class="fs-lms-import-form" enctype="multipart/form-data">

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="fs-import-subject">Предмет</label></th>
						<td>
							<select id="fs-import-subject" name="subject_key" required>
								<option value="">— Выберите предмет —</option>
								<?php foreach ( $subjects as $subject ) : ?>
									<option value="<?php echo esc_attr( $subject->key ); ?>">
										<?php echo esc_html( $subject->name ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="fs-import-period">Учебный период</label></th>
						<td>
							<select id="fs-import-period" name="period_id" required>
								<option value="">— Выберите период —</option>
								<?php foreach ( $academic_periods as $period ) : ?>
									<option value="<?php echo esc_attr( $period['id'] ); ?>">
										<?php echo esc_html( $period['name'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="fs-import-csv-file">CSV-файл</label></th>
						<td>
							<input type="file" id="fs-import-csv-file" name="file" accept=".csv" required>
						</td>
					</tr>
					<tr>
						<th scope="row">Режим</th>
						<td>
							<label for="fs-import-dry-run">
								<input type="checkbox" id="fs-import-dry-run" name="dry_run" value="1">
								Только проверить (dry-run) — без записи в базу
							</label>
						</td>
					</tr>
				</tbody>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary" id="fs-import-submit">

                    Импортировать</button>
			</p>

		</form>

		<div id="fs-import-report" class="fs-import-report" hidden></div>

	<?php endif; ?>

</div>
