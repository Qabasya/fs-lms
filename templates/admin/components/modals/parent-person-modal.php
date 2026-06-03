<?php
/**
 * Модальное окно карточки родителя/представителя.
 *
 * @package FS LMS
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
?>

<div id="fs-parent-person-modal" class="fs-lms-modal hidden" data-person-id="" data-wp-user-id="">
	<div class="fs-lms-modal-backdrop"></div>

	<div class="fs-lms-modal-content fs-modal-lg">
		<div class="fs-lms-modal-header">
			<h2 class="fs-lms-modal-title"></h2>
			<button type="button" class="fs-lms-modal-close fs-close js-modal-close">&times;</button>
		</div>

		<div class="fs-lms-modal-body">

			<div class="fs-form-row">
				<div class="fs-form-group">
					<label><?php esc_html_e( 'ФИО', 'fs-lms' ); ?></label>
					<input type="text" class="fs-person-field regular-text" data-field="display_name" readonly>
				</div>
				<div class="fs-form-group">
					<label><?php esc_html_e( 'Роль', 'fs-lms' ); ?></label>
					<input type="text" class="fs-person-field regular-text" data-field="relation_type" readonly>
				</div>
			</div>

			<div class="fs-form-row">
				<div class="fs-form-group">
					<label><?php esc_html_e( 'Телефон', 'fs-lms' ); ?></label>
					<input type="text" class="fs-person-field regular-text" data-field="phone" readonly>
				</div>
				<div class="fs-form-group">
					<label><?php esc_html_e( 'Почта', 'fs-lms' ); ?></label>
					<input type="text" class="fs-person-field regular-text" data-field="email" readonly>
				</div>
			</div>

			<div class="fs-form-row">
				<div class="fs-form-group">
					<label><?php esc_html_e( 'ФИО подопечного', 'fs-lms' ); ?></label>
					<input type="text" class="fs-person-field regular-text" data-field="dependent_name" readonly>
				</div>
			</div>

			<hr class="pvm-mask-divider">

			<div class="fs-person-reveal-bar">
				<button type="button" class="button js-reveal-all"><?php esc_html_e( 'Показать данные', 'fs-lms' ); ?></button>
			</div>

			<div class="fs-form-row">
				<div class="fs-form-group">
					<label><?php esc_html_e( 'Пароль', 'fs-lms' ); ?></label>
					<input type="text" class="fs-person-field fs-person-pii regular-text" data-field="password" readonly>
				</div>
			</div>

			<div class="fs-form-row">
				<div class="fs-form-group">
					<label><?php esc_html_e( 'Документ родителя', 'fs-lms' ); ?></label>
					<input type="text" class="fs-person-field fs-person-pii regular-text" data-field="doc_number" readonly>
				</div>
				<div class="fs-form-group">
					<label><?php esc_html_e( 'ИНН родителя', 'fs-lms' ); ?></label>
					<input type="text" class="fs-person-field fs-person-pii regular-text" data-field="inn" readonly>
				</div>
				<div class="fs-form-group">
					<label><?php esc_html_e( 'Дата рождения родителя', 'fs-lms' ); ?></label>
					<input type="text" class="fs-person-field regular-text" data-field="birth_date" readonly>
				</div>
			</div>

			<div class="fs-form-row">
				<div class="fs-form-group">
					<label><?php esc_html_e( 'Документ ребёнка', 'fs-lms' ); ?></label>
					<input type="text" class="fs-person-field regular-text" data-field="child_doc_number" readonly>
				</div>
				<div class="fs-form-group">
					<label><?php esc_html_e( 'ИНН ребёнка', 'fs-lms' ); ?></label>
					<input type="text" class="fs-person-field regular-text" data-field="child_inn" readonly>
				</div>
				<div class="fs-form-group">
					<label><?php esc_html_e( 'Дата рождения ребёнка', 'fs-lms' ); ?></label>
					<input type="text" class="fs-person-field regular-text" data-field="child_birth_date" readonly>
				</div>
			</div>

			<div class="fs-form-row">
				<div class="fs-form-group">
					<label><?php esc_html_e( 'Прописка', 'fs-lms' ); ?></label>
					<input type="text" class="fs-person-field fs-person-pii large-text" data-field="address" readonly>
				</div>
			</div>

		</div><!-- /.fs-lms-modal-body -->

		<div class="fs-lms-modal-footer">
			<button type="button" class="button js-pmm-close"><?php esc_html_e( 'Закрыть', 'fs-lms' ); ?></button>
			<button type="button" class="button js-pmm-edit"><?php esc_html_e( 'Редактировать', 'fs-lms' ); ?></button>
			<button type="button" class="button js-pmm-export"><?php esc_html_e( 'Экспорт', 'fs-lms' ); ?></button>
			<button type="button" class="button button-link-delete js-pmm-delete"><?php esc_html_e( 'Удалить', 'fs-lms' ); ?></button>
		</div>
	</div>
</div>
