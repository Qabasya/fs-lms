<?php
/**
 * Страница «Центр печати»: сборка документа по DOCX-шаблону для ученика.
 *
 * Поведение — src/js/admin/services/print-center.js.
 *
 * @var array<int, array{value: string, label: string, button_label: string, has_template: bool}> $documents    Формы документов
 * @var array<string, \Inc\Enums\Print\PrintField[]>                                               $field_groups Справочник полей по разделам
 * @var array<int, array{key: string, name: string, program: string, price: string}>              $programs     Программа и цена по предметам
 * @var bool                                                                                        $can_export   Есть право выгружать ПД
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$first_available = null;
foreach ( $documents as $doc ) {
	if ( $doc['has_template'] ) {
		$first_available = $doc;
		break;
	}
}
$button_label = $first_available['button_label'] ?? $documents[0]['button_label'];
?>

<div class="wrap fs-print-center">
	<div class="fs-page-header">
		<div class="fs-page-header__content">
			<h1 class="fs-page-header__title">Центр печати</h1>
		</div>
		<p class="fs-page-header__desc">
			Выберите ученика и форму документа — данные ученика и родителя подставятся в шаблон,
			готовый файл Word скачается для печати.
		</p>
	</div>

	<hr class="wp-header-end">

	<?php if ( ! $can_export ) : ?>
		<div class="notice notice-warning inline">
			<p>Документ содержит персональные данные родителя. Для его формирования нужно право на выгрузку ПД — обратитесь к администратору.</p>
		</div>
	<?php endif; ?>

	<div class="fs-print-center__layout">
		<div class="fs-print-center__column">
		<form id="fs-print-center-form" class="fs-card fs-card--flat fs-print-center__form" autocomplete="off">
			<div class="fs-card__header">
				<h2 class="fs-card__title">Документ</h2>
			</div>

			<div class="fs-card__body">
				<div class="fs-field">
					<label for="fs-print-student" class="fs-field__label">Ученик</label>
					<div class="fs-field__control fs-print-center__search">
						<input type="text" id="fs-print-student" placeholder="Начните вводить ФИО"
							role="combobox" aria-expanded="false" aria-controls="fs-print-student-list" aria-autocomplete="list">
						<ul id="fs-print-student-list" class="fs-print-center__suggest" role="listbox" hidden></ul>
					</div>
					<p class="fs-field__desc" data-print-student-hint hidden></p>
				</div>

				<div class="fs-field" data-print-record-field hidden>
					<label for="fs-print-record" class="fs-field__label">Направление</label>
					<div class="fs-field__control">
						<select id="fs-print-record"></select>
					</div>
					<p class="fs-field__desc">Ученик учится на нескольких направлениях — у каждого свой договор.</p>
				</div>

				<div class="fs-field" data-print-parent-field hidden>
					<span class="fs-field__label">Родитель (заказчик)</span>
					<p class="fs-print-center__parent" data-print-parent></p>
				</div>

				<div class="fs-field">
					<label for="fs-print-document" class="fs-field__label">Форма документа</label>
					<div class="fs-field__control">
						<select id="fs-print-document">
							<?php foreach ( $documents as $doc ) : ?>
								<option value="<?php echo esc_attr( $doc['value'] ); ?>"
									data-button-label="<?php echo esc_attr( $doc['button_label'] ); ?>"
									<?php disabled( ! $doc['has_template'] ); ?>
									<?php selected( null !== $first_available && $first_available['value'] === $doc['value'] ); ?>>
									<?php echo esc_html( $doc['label'] . ( $doc['has_template'] ? '' : ' — шаблон не загружен' ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>
			</div>

			<div class="fs-card__footer">
				<button type="submit" class="button button-primary" data-print-submit disabled
					<?php echo $can_export ? '' : 'data-print-locked'; ?>>
					<?php echo esc_html( $button_label ); ?>
				</button>
				<span class="fs-print-center__status" data-print-status role="status"></span>
			</div>
		</form>

		<section class="fs-card fs-card--flat" aria-labelledby="fs-print-programs-title">
			<div class="fs-card__header">
				<h2 class="fs-card__title" id="fs-print-programs-title">Программы и цены</h2>
			</div>
			<div class="fs-card__body">
				<p class="fs-card__desc">
					Подставляются в договор по предмету группы: <code>{{program}}</code> — название программы,
					<code>{{price}}</code> — стоимость месяца обучения (печатается как написано).
				</p>
				<table class="widefat striped fs-print-center__table fs-print-center__programs">
					<thead>
						<tr>
							<th>Предмет</th>
							<th>Программа</th>
							<th>Цена в месяц</th>
							<th><span class="screen-reader-text">Действие</span></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $programs as $row ) : ?>
							<tr data-print-program="<?php echo esc_attr( $row['key'] ); ?>">
								<td><?php echo esc_html( $row['name'] ); ?></td>
								<td>
									<input type="text" class="fs-print-center__program-input" data-field="program"
										value="<?php echo esc_attr( $row['program'] ); ?>"
										aria-label="<?php echo esc_attr( 'Программа: ' . $row['name'] ); ?>">
								</td>
								<td>
									<input type="text" class="fs-print-center__price-input" data-field="price"
										value="<?php echo esc_attr( $row['price'] ); ?>" placeholder="10 000 руб."
										aria-label="<?php echo esc_attr( 'Цена: ' . $row['name'] ); ?>">
								</td>
								<td>
									<button type="button" class="button" data-print-program-save disabled>Сохранить</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</section>
		</div>

		<section class="fs-card fs-card--flat fs-print-center__fields" aria-labelledby="fs-print-fields-title">
			<div class="fs-card__header">
				<h2 class="fs-card__title" id="fs-print-fields-title">Поля для шаблона</h2>
			</div>
			<div class="fs-card__body">
				<p class="fs-card__desc">
					В шаблоне DOCX поле пишется в двойных фигурных скобках, например <code>{{parent_full_name}}</code>.
					Нажмите на поле, чтобы скопировать его.
				</p>

				<?php foreach ( $field_groups as $group_label => $fields ) : ?>
					<details class="fs-print-center__group">
					<summary class="fs-print-center__group-title">
						<?php echo esc_html( $group_label ); ?>
						<span class="fs-print-center__group-count"><?php echo (int) count( $fields ); ?></span>
					</summary>
					<table class="widefat striped fs-print-center__table">
						<tbody>
							<?php foreach ( $fields as $field ) : ?>
								<tr>
									<td>
										<button type="button" class="fs-print-center__code" data-print-copy="<?php echo esc_attr( '{{' . $field->value . '}}' ); ?>">
											<code><?php echo esc_html( '{{' . $field->value . '}}' ); ?></code>
										</button>
									</td>
									<td><?php echo esc_html( $field->label() ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					</details>
				<?php endforeach; ?>
			</div>
		</section>
	</div>
</div>
