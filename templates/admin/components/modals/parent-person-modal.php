<?php
/**
 * Модальное окно карточки родителя/представителя.
 *
 * @package FS LMS
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$copy_btn = '<button type="button" class="fs-copy-field__btn" aria-label="Копировать">'
	. '<span class="dashicons dashicons-clipboard fs-copy-field__icon"></span>'
	. '<span class="fs-copy-field__label">Скопировано</span>'
	. '</button>';
?>

<div id="fs-parent-person-modal" class="fs-lms-modal hidden" data-person-id="" data-wp-user-id="">
	<div class="fs-lms-modal-backdrop"></div>

	<div class="fs-lms-modal-content fs-modal-xl">
		<div class="fs-lms-modal-header">
			<h2 class="fs-lms-modal-title"></h2>
			<button type="button" class="fs-lms-modal-close fs-close js-modal-close">&times;</button>
		</div>

		<div class="fs-lms-modal-body">

			<div class="fs-form-row">
				<div class="fs-form-group">
					<label><?php esc_html_e( 'Фамилия', 'fs-lms' ); ?></label>
					<div class="fs-pfield fs-pfield--editable">
						<input type="text" class="fs-person-field" data-field="last_name" readonly>
						<?php echo $copy_btn; // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</div>
				</div>
				<div class="fs-form-group">
					<label><?php esc_html_e( 'Имя', 'fs-lms' ); ?></label>
					<div class="fs-pfield fs-pfield--editable">
						<input type="text" class="fs-person-field" data-field="first_name" readonly>
						<?php echo $copy_btn; // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</div>
				</div>
				<div class="fs-form-group">
					<label><?php esc_html_e( 'Отчество', 'fs-lms' ); ?></label>
					<div class="fs-pfield fs-pfield--editable">
						<input type="text" class="fs-person-field" data-field="middle_name" readonly>
						<?php echo $copy_btn; // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</div>
				</div>
				<div class="fs-form-group">
					<label><?php esc_html_e( 'Роль', 'fs-lms' ); ?></label>
					<input type="text" class="fs-person-field regular-text" data-field="relation_type" data-no-edit readonly>
				</div>
			</div>

			<div class="fs-form-row">
				<div class="fs-form-group">
					<label><?php esc_html_e( 'Телефон', 'fs-lms' ); ?></label>
					<div class="fs-pfield fs-pfield--editable">
						<input type="text" class="fs-person-field" data-field="phone" readonly>
						<?php echo $copy_btn; // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</div>
				</div>
				<div class="fs-form-group">
					<label><?php esc_html_e( 'Почта', 'fs-lms' ); ?></label>
					<div class="fs-pfield fs-pfield--editable">
						<input type="text" class="fs-person-field" data-field="email" readonly>
						<?php echo $copy_btn; // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</div>
				</div>
			</div>

			<div class="fs-form-row">
				<div class="fs-form-group">
					<label><?php esc_html_e( 'ФИО ребёнка', 'fs-lms' ); ?></label>
					<div class="fs-pfield">
						<input type="text" class="fs-person-field" data-field="dependent_name" data-no-edit readonly>
						<?php echo $copy_btn; // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</div>
				</div>
			</div>

			<hr class="pvm-mask-divider">

			<div class="fs-person-reveal-bar">
				<button type="button" class="button js-reveal-all"><?php esc_html_e( 'Показать данные', 'fs-lms' ); ?></button>
			</div>

			<div class="fs-form-row">
				<div class="fs-form-group">
					<label><?php esc_html_e( 'Пароль', 'fs-lms' ); ?></label>
					<div class="fs-pfield fs-pfield--editable fs-pfield--pii">
						<input type="text" class="fs-person-field fs-person-pii" data-field="password" readonly>
						<?php echo $copy_btn; // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</div>
				</div>
			</div>

			<div class="fs-form-row">
				<div class="fs-form-group">
					<label><?php esc_html_e( 'Документ родителя', 'fs-lms' ); ?></label>
					<div class="fs-pfield fs-pfield--editable fs-pfield--pii">
						<input type="text" class="fs-person-field fs-person-pii" data-field="doc_number" readonly>
						<?php echo $copy_btn; // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</div>
				</div>
				<div class="fs-form-group">
					<label><?php esc_html_e( 'ИНН родителя', 'fs-lms' ); ?></label>
					<div class="fs-pfield fs-pfield--editable fs-pfield--pii">
						<input type="text" class="fs-person-field fs-person-pii" data-field="inn" readonly>
						<?php echo $copy_btn; // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</div>
				</div>
				<div class="fs-form-group">
					<label><?php esc_html_e( 'Дата рождения родителя', 'fs-lms' ); ?></label>
					<div class="fs-pfield fs-pfield--editable">
						<input type="date" class="fs-person-field" data-field="birth_date" readonly>
						<?php echo $copy_btn; // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</div>
				</div>
			</div>

			<div class="fs-form-row">
				<div class="fs-form-group">
					<label><?php esc_html_e( 'Выдан', 'fs-lms' ); ?></label>
					<div class="fs-pfield fs-pfield--pii">
						<input type="text" class="fs-person-field fs-person-pii" data-field="doc_issued" data-no-edit readonly>
						<?php echo $copy_btn; // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</div>
				</div>
			</div>

			<div class="fs-form-row">
				<div class="fs-form-group">
					<label><?php esc_html_e( 'Документ ребёнка', 'fs-lms' ); ?></label>
					<div class="fs-pfield fs-pfield--editable">
						<input type="text" class="fs-person-field" data-field="child_doc_number" readonly>
						<?php echo $copy_btn; // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</div>
				</div>
				<div class="fs-form-group">
					<label><?php esc_html_e( 'ИНН ребёнка', 'fs-lms' ); ?></label>
					<div class="fs-pfield fs-pfield--editable">
						<input type="text" class="fs-person-field" data-field="child_inn" readonly>
						<?php echo $copy_btn; // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</div>
				</div>
				<div class="fs-form-group">
					<label><?php esc_html_e( 'Дата рождения ребёнка', 'fs-lms' ); ?></label>
					<div class="fs-pfield fs-pfield--editable">
						<input type="date" class="fs-person-field" data-field="child_birth_date" readonly>
						<?php echo $copy_btn; // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</div>
				</div>
			</div>

			<div class="fs-form-row">
				<div class="fs-form-group">
					<label><?php esc_html_e( 'Прописка', 'fs-lms' ); ?></label>
					<div class="fs-pfield fs-pfield--editable fs-pfield--pii">
						<input type="text" class="fs-person-field fs-person-pii" data-field="address" readonly>
						<?php echo $copy_btn; // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</div>
				</div>
			</div>

		</div><!-- /.fs-lms-modal-body -->

		<div class="fs-lms-modal-footer">
			<button type="button" class="button js-pmm-close"><?php esc_html_e( 'Закрыть', 'fs-lms' ); ?></button>
			<button type="button" class="button js-pmm-edit"><?php esc_html_e( 'Редактировать', 'fs-lms' ); ?></button>
			<button type="button" class="button js-pmm-export"><?php esc_html_e( 'Экспорт', 'fs-lms' ); ?></button>
		</div>
	</div>
</div>
