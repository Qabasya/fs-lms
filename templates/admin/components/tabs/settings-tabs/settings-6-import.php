<?php

declare( strict_types=1 );

use Inc\DTO\Subject\SubjectDTO;
use Inc\Enums\Import\ImportColumn;
use Inc\Enums\Import\ImportMode;

defined( 'ABSPATH' ) || exit;

/**
 * Таб «Импорт» — загрузка учеников из CSV в выбранные предмет и период.
 *
 * Два режима: архивные записи (без учёток WP) и полное зачисление
 * (с созданием учёток ученика и родителя).
 *
 * @var SubjectDTO[] $subjects         Список предметов
 * @var array                         $academic_periods Список учебных периодов
 */
?>

<div id="tab-import" class="tab-pane active">
<div class="fs-lms-import">

	<div class="fs-page-header">
		<div class="fs-page-header__content">
			<h2 class="fs-page-header__title">Импорт учеников из CSV</h2>
			<div class="fs-page-header__actions">
				<button
					type="button"
					class="button"
					id="fs-import-template"
					data-headers-archive="<?php echo esc_attr( implode( ';', ImportColumn::headers( ImportMode::Archive ) ) ); ?>"
					data-examples-archive="<?php echo esc_attr( (string) wp_json_encode( ImportColumn::exampleRows( ImportMode::Archive ), JSON_UNESCAPED_UNICODE ) ); ?>"
					data-headers-enrolled="<?php echo esc_attr( implode( ';', ImportColumn::headers( ImportMode::Enrolled ) ) ); ?>"
					data-examples-enrolled="<?php echo esc_attr( (string) wp_json_encode( ImportColumn::exampleRows( ImportMode::Enrolled ), JSON_UNESCAPED_UNICODE ) ); ?>">
					<span class="dashicons dashicons-download"></span>
					Скачать шаблон CSV
				</button>
			</div>
		</div>
		<p class="fs-page-header__desc">
			Одна строка файла = ученик + родитель + группа + договор. Группы, ученики, родители
			и записи о зачислении создаются автоматически. Предмет и период выбираются ниже и
			применяются ко всем строкам. Режим «Архивные записи» учётных записей WP не создаёт;
			режим «Полное зачисление» создаёт учётки ученика (логин и пароль — из файла)
			и родителя (логин — email, пароль генерируется).
		</p>
	</div>

	<?php if ( empty( $subjects ) || empty( $academic_periods ) ) : ?>

		<div class="notice notice-warning inline">
			<p>Для импорта нужен хотя бы один предмет и один учебный период. Создайте их на вкладках «Предметы» и «Периоды».</p>
		</div>

	<?php else : ?>

		<form id="fs-lms-import-form" class="fs-lms-import-form" enctype="multipart/form-data">
			<div class="fs-card">
				<div class="fs-card__header">
					<h2 class="fs-card__title">Параметры импорта</h2>
				</div>
				<div class="fs-card__body">

					<p class="fs-card__desc">
						Тип документа: <code>Паспорт</code> или <code>Свидетельство о рождении</code>.
					</p>
					<p class="fs-card__desc" id="fs-import-archive-note">
						Колонки отчисления необязательны: если они заполнены, запись создаётся в архиве.
						Причина отчисления: <code>Окончание курса</code>, <code>Перевод</code>,
						<code>По собственному желанию</code>; все остальные значения попадают в причину «Другое».
					</p>

					<div class="fs-field">
						<span class="fs-field__label">Режим импорта</span>
						<div class="fs-field__control">
							<label>
								<input type="radio" name="mode" value="<?php echo esc_attr( ImportMode::Archive->value ); ?>" checked>
								Архивные записи (без учёток)
							</label>
							<label>
								<input type="radio" name="mode" value="<?php echo esc_attr( ImportMode::Enrolled->value ); ?>">
								Полное зачисление (с учётками)
							</label>
						</div>
					</div>

					<div class="fs-field" id="fs-import-send-emails-field" hidden>
						<span class="fs-field__label">Письма</span>
						<label>
							<input type="checkbox" id="fs-import-send-emails" name="send_emails" value="1">
							Отправить письма родителям с логином и паролем
						</label>
					</div>

					<div class="fs-field">
						<label class="fs-field__label" for="fs-import-subject">Предмет</label>
						<div class="fs-field__control">
							<select id="fs-import-subject" name="subject_key" required>
								<option value="">— Выберите предмет —</option>
								<?php foreach ( $subjects as $subject ) : ?>
									<option value="<?php echo esc_attr( $subject->key ); ?>">
										<?php echo esc_html( $subject->name ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>
					</div>

					<div class="fs-field">
						<label class="fs-field__label" for="fs-import-period">Учебный период</label>
						<div class="fs-field__control">
							<select id="fs-import-period" name="period_id" required>
								<option value="">— Выберите период —</option>
								<?php foreach ( $academic_periods as $period ) : ?>
									<option value="<?php echo esc_attr( $period['id'] ); ?>">
										<?php echo esc_html( $period['name'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>
					</div>

					<div class="fs-field">
						<label class="fs-field__label" for="fs-import-csv-file">CSV-файл</label>
						<div class="fs-field__control">
							<input type="file" id="fs-import-csv-file" name="file" accept=".csv" required>
						</div>
					</div>

					<div class="fs-field">
						<span class="fs-field__label">Проверка</span>
						<label>
							<input type="checkbox" id="fs-import-dry-run" name="dry_run" value="1">
							Только проверить (dry-run) — без записи в базу
						</label>
					</div>

				</div>
				<div class="fs-card__footer">
					<button type="submit" class="button button-primary" id="fs-import-submit">
						Импортировать
					</button>
				</div>
			</div>
		</form>

		<div id="fs-import-report" class="fs-import-report" hidden></div>

	<?php endif; ?>

</div>
</div>
