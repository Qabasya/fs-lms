<?php

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;
?>

<div id="fs-group-students-modal" class="fs-lms-modal hidden">
	<div class="fs-lms-modal-backdrop"></div>

	<div class="fs-lms-modal-content fs-modal-lg">
		<div class="fs-lms-modal-header">
			<h2 class="fs-lms-modal-title">Ученики группы</h2>
			<button type="button" class="fs-lms-modal-close fs-close js-modal-close" aria-label="Закрыть">&times;</button>
		</div>

		<div class="fs-lms-modal-body">
			<p class="fs-group-students-count description"></p>

			<div class="fs-group-add-students hidden">
				<div class="fs-group-add-students__controls">
					<input type="search" class="regular-text js-add-student-query" placeholder="Поиск ученика по имени, email или логину…">
					<button type="button" class="button js-add-student-search">Найти</button>
					<button type="button" class="button button-primary js-add-students-submit" disabled>Добавить выбранных</button>
				</div>
				<div class="fs-group-add-students__results"></div>

				<div class="fs-group-add-students__bulk">
					<label for="fs-group-bulk-subject">Добавить всех учеников предмета</label>
					<div class="fs-group-add-students__controls">
						<select id="fs-group-bulk-subject" class="js-bulk-subject">
							<option value="">— Выберите предмет —</option>
							<?php foreach ( $active_subjects ?? $subjects ?? [] as $subject_key => $subject ) : ?>
								<option value="<?php echo esc_attr( (string) $subject_key ); ?>">
									<?php echo esc_html( $subject->name ?? $subject_key ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<button type="button" class="button js-bulk-plan" disabled>Подобрать</button>
						<button type="button" class="button button-primary js-bulk-confirm hidden">Добавить</button>
					</div>
					<p class="description fs-group-add-students__bulk-status js-bulk-status hidden"></p>
				</div>
			</div>

			<div class="fs-group-students-content">
				<p class="description">Загрузка…</p>
			</div>
		</div>

		<div class="fs-lms-modal-footer">
			<button type="button" class="button fs-lms-modal-cancel js-modal-close">Закрыть</button>
		</div>
	</div>
</div>
