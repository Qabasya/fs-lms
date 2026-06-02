<?php
/**
 * Модальное окно карточки ученика.
 * Поля выше маски — всегда видны. Ниже маски — зашифрованные, раскрываются через AJAX.
 *
 * @package FS LMS
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'fs_pii_reveal_cell' ) ) {
	function fs_pii_reveal_cell( string $field ): string {
		return '<div class="fs-pii-field" data-field="' . esc_attr( $field ) . '">'
			. '<span class="fs-pii-field__masked">••••••</span>'
			. '<span class="fs-pii-field__revealed" hidden></span>'
			. '<button type="button" class="button-link js-pii-reveal" data-field="' . esc_attr( $field ) . '">'
			. '<span class="dashicons dashicons-visibility"></span> ' . esc_html__( 'Показать', 'fs-lms' )
			. '</button>'
			. '</div>';
	}
}
?>

<div id="fs-student-person-modal" class="fs-lms-modal hidden" data-person-id="" data-wp-user-id="">
	<div class="fs-lms-modal-backdrop"></div>

	<div class="fs-lms-modal-content fs-modal-lg">
		<div class="fs-lms-modal-header">
			<h2 class="fs-lms-modal-title"></h2>
			<button type="button" class="fs-lms-modal-close fs-close js-modal-close">&times;</button>
		</div>

		<div class="fs-lms-modal-body">

			<!-- Режим просмотра -->
			<div class="pmm-view">
				<div class="fs-form-row">
					<div class="fs-form-group"><label><?php esc_html_e( 'ФИО', 'fs-lms' ); ?></label><p data-val="display_name"></p></div>
					<div class="fs-form-group"><label><?php esc_html_e( '№ договора', 'fs-lms' ); ?></label><p data-val="contract_no"></p></div>
				</div>
				<div class="fs-form-row">
					<div class="fs-form-group"><label><?php esc_html_e( 'Предмет', 'fs-lms' ); ?></label><p data-val="subject"></p></div>
					<div class="fs-form-group"><label><?php esc_html_e( 'Группа', 'fs-lms' ); ?></label><p data-val="group"></p></div>
					<div class="fs-form-group"><label><?php esc_html_e( 'Расписание', 'fs-lms' ); ?></label><p data-val="schedule">—</p></div>
				</div>
				<div class="fs-form-row">
					<div class="fs-form-group"><label><?php esc_html_e( 'Телефон', 'fs-lms' ); ?></label><p data-val="phone"></p></div>
					<div class="fs-form-group"><label><?php esc_html_e( 'Почта', 'fs-lms' ); ?></label><p data-val="email"></p></div>
				</div>
				<div class="fs-form-row">
					<div class="fs-form-group"><label><?php esc_html_e( 'ФИО родителя', 'fs-lms' ); ?></label><p data-val="guardian_name"></p></div>
				</div>

				<hr class="pvm-mask-divider">

				<div class="fs-form-row">
					<div class="fs-form-group"><label><?php esc_html_e( 'Школа', 'fs-lms' ); ?></label><p data-val="school"></p></div>
					<div class="fs-form-group"><label><?php esc_html_e( 'Класс', 'fs-lms' ); ?></label><p data-val="grade"></p></div>
				</div>
				<div class="fs-form-row">
					<div class="fs-form-group"><label><?php esc_html_e( 'Документ', 'fs-lms' ); ?></label><?php echo fs_pii_reveal_cell( 'doc_number' ); ?></div>
					<div class="fs-form-group"><label><?php esc_html_e( 'ИНН', 'fs-lms' ); ?></label><?php echo fs_pii_reveal_cell( 'inn' ); ?></div>
					<div class="fs-form-group"><label><?php esc_html_e( 'Дата рождения', 'fs-lms' ); ?></label><p data-val="birth_date"></p></div>
				</div>
			</div><!-- /.pmm-view -->

			<!-- Режим редактирования -->
			<div class="pmm-edit" hidden>
				<p class="description"><?php esc_html_e( 'Заполните только изменяемые поля. Пустые поля не обновляются.', 'fs-lms' ); ?></p>
				<div class="fs-form-row">
					<div class="fs-form-group"><label><?php esc_html_e( 'ФИО', 'fs-lms' ); ?></label><input type="text" name="full_name" class="regular-text"></div>
					<div class="fs-form-group"><label><?php esc_html_e( 'Почта', 'fs-lms' ); ?></label><input type="email" name="email" class="regular-text"></div>
				</div>
				<div class="fs-form-row">
					<div class="fs-form-group"><label><?php esc_html_e( 'Телефон', 'fs-lms' ); ?></label><input type="tel" name="phone" class="regular-text"></div>
					<div class="fs-form-group"><label><?php esc_html_e( 'Документ', 'fs-lms' ); ?></label><input type="text" name="doc_number" class="regular-text"></div>
				</div>
				<div class="fs-form-row">
					<div class="fs-form-group"><label><?php esc_html_e( 'ИНН', 'fs-lms' ); ?></label><input type="text" name="inn" class="regular-text"></div>
				</div>
				<div class="fs-form-group">
					<label><?php esc_html_e( 'Адрес', 'fs-lms' ); ?></label>
					<input type="text" name="address" class="large-text">
				</div>
			</div><!-- /.pmm-edit -->

		</div><!-- /.fs-lms-modal-body -->

		<div class="fs-lms-modal-footer">
			<button type="button" class="button js-pmm-edit"><?php esc_html_e( 'Редактировать', 'fs-lms' ); ?></button>
			<button type="button" class="button js-pmm-export"><?php esc_html_e( 'Экспорт ПД', 'fs-lms' ); ?></button>
			<button type="button" class="button button-link-delete js-pmm-delete"><?php esc_html_e( 'Удалить ПД', 'fs-lms' ); ?></button>
			<button type="button" class="button button-primary js-pmm-save" hidden><?php esc_html_e( 'Сохранить', 'fs-lms' ); ?></button>
			<button type="button" class="button js-pmm-cancel" hidden><?php esc_html_e( 'Отмена', 'fs-lms' ); ?></button>
			<button type="button" class="button js-pmm-close"><?php esc_html_e( 'Закрыть', 'fs-lms' ); ?></button>
		</div>
	</div>
</div>
