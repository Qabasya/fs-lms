<?php
/**
 * Модальное окно карточки ученика.
 *
 * @package FS LMS
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
?>

<div id="fs-student-person-modal" class="fs-lms-modal hidden" data-person-id="" data-wp-user-id="">
	<div class="fs-lms-modal-backdrop"></div>

	<div class="fs-lms-modal-content fs-modal-lg">
		<div class="fs-lms-modal-header">
			<h2 class="fs-lms-modal-title"></h2>
			<button type="button" class="fs-lms-modal-close fs-close js-modal-close">&times;</button>
		</div>

		<div class="fs-lms-modal-body">

			<div class="fs-form-row">
				<div class="fs-form-group">
					<label><?php esc_html_e( 'Фамилия', 'fs-lms' ); ?></label>
					<input type="text" class="fs-person-field regular-text" data-field="last_name" readonly>
				</div>
				<div class="fs-form-group">
					<label><?php esc_html_e( 'Имя', 'fs-lms' ); ?></label>
					<input type="text" class="fs-person-field regular-text" data-field="first_name" readonly>
				</div>
				<div class="fs-form-group">
					<label><?php esc_html_e( 'Отчество', 'fs-lms' ); ?></label>
					<input type="text" class="fs-person-field regular-text" data-field="middle_name" readonly>
				</div>
				<div class="fs-form-group">
					<label><?php esc_html_e( '№ договора', 'fs-lms' ); ?></label>
					<input type="text" class="fs-person-field regular-text" data-field="contract_no" data-no-edit readonly>
				</div>
			</div>

			<div class="fs-form-row">
				<div class="fs-form-group">
					<label><?php esc_html_e( 'Предмет', 'fs-lms' ); ?></label>
					<input type="text" class="fs-person-field regular-text" data-field="subject" data-no-edit readonly>
				</div>
				<div class="fs-form-group">
					<label><?php esc_html_e( 'Группа', 'fs-lms' ); ?></label>
					<input type="text" class="fs-person-field regular-text" data-field="group" data-no-edit readonly>
				</div>
				<div class="fs-form-group">
					<label><?php esc_html_e( 'Расписание', 'fs-lms' ); ?></label>
					<input type="text" class="fs-person-field regular-text" data-field="schedule" data-no-edit readonly>
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
				<div class="fs-form-group">
					<label><?php esc_html_e( 'Дата рождения', 'fs-lms' ); ?></label>
					<input type="date" class="fs-person-field regular-text" data-field="birth_date" readonly>
				</div>
			</div>

			<div class="fs-form-row">
				<div class="fs-form-group">
					<label><?php esc_html_e( 'Логин', 'fs-lms' ); ?></label>
					<input type="text" class="fs-person-field regular-text" data-field="login" readonly>
				</div>
				<div class="fs-form-group">
					<label><?php esc_html_e( 'Пароль', 'fs-lms' ); ?></label>
					<input type="text" class="fs-person-field regular-text" data-field="password" readonly>
				</div>
			</div>

			<div class="fs-form-row">
				<div class="fs-form-group">
					<label><?php esc_html_e( 'ФИО родителя', 'fs-lms' ); ?></label>
					<input type="text" class="fs-person-field regular-text" data-field="guardian_name" data-no-edit readonly>
				</div>
			</div>

			<div class="fs-form-row">
				<div class="fs-form-group">
					<label><?php esc_html_e( 'Школа', 'fs-lms' ); ?></label>
					<input type="text" class="fs-person-field regular-text" data-field="school" readonly>
				</div>
				<div class="fs-form-group">
					<label><?php esc_html_e( 'Класс', 'fs-lms' ); ?></label>
					<input type="text" class="fs-person-field regular-text" data-field="grade" data-no-edit readonly>
				</div>
			</div>

			<hr class="pvm-mask-divider">

			<div class="fs-person-reveal-bar">
				<button type="button" class="button js-reveal-all"><?php esc_html_e( 'Показать данные', 'fs-lms' ); ?></button>
			</div>

			<div class="fs-form-row">
				<div class="fs-form-group">
					<label><?php esc_html_e( 'Документ', 'fs-lms' ); ?></label>
					<input type="text" class="fs-person-field fs-person-pii regular-text" data-field="doc_number" readonly>
				</div>
				<div class="fs-form-group">
					<label><?php esc_html_e( 'ИНН', 'fs-lms' ); ?></label>
					<input type="text" class="fs-person-field fs-person-pii regular-text" data-field="inn" readonly>
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
