<?php
/**
 * Модальное окно просмотра заявки (read-only).
 * Открывается из userlist-1-applications.php по клику на .js-view-application.
 * Используется для статусов Enrolling, Converted, Expired, Trash.
 *
 * @package FS LMS
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
?>

<div id="fs-application-view-modal" class="fs-lms-modal hidden">
	<div class="fs-lms-modal-backdrop"></div>

	<div class="fs-lms-modal-content fs-modal-lg">
		<div class="fs-lms-modal-header">
			<h2 class="fs-lms-modal-title"><?php esc_html_e( 'Заявка', 'fs-lms' ); ?></h2>
			<button type="button" class="fs-lms-modal-close fs-close js-modal-close" aria-label="<?php esc_attr_e( 'Закрыть', 'fs-lms' ); ?>">&times;</button>
		</div>

		<div class="fs-lms-modal-body">
			<div class="fs-modal-accordion">

				<!-- Данные ученика -->
				<div class="fs-modal-accordion__item">
					<button type="button" class="fs-modal-accordion__header" aria-expanded="true" aria-controls="avm-acc-student">
						<h3><?php esc_html_e( 'Данные ученика', 'fs-lms' ); ?></h3>
						<span class="dashicons dashicons-arrow-down-alt2"></span>
					</button>
					<div class="fs-modal-accordion__body" id="avm-acc-student">
						<div class="fs-form-row">
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Фамилия', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-avm="s_last_name"></p>
							</div>
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Имя', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-avm="s_first_name"></p>
							</div>
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Отчество', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-avm="s_middle_name"></p>
							</div>
						</div>
						<div class="fs-form-row">
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Дата рождения', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-avm="s_birth_date"></p>
							</div>
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Email', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-avm="s_email"></p>
							</div>
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Телефон', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-avm="s_phone"></p>
							</div>
						</div>
						<div class="fs-form-row">
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Школа', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-avm="s_school"></p>
							</div>
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Класс', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-avm="s_grade"></p>
							</div>
						</div>
						<div class="fs-form-row">
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Тип документа', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-avm="s_doc_type"></p>
							</div>
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Номер документа', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-avm="s_doc_number"></p>
							</div>
							<div class="fs-form-group">
								<label><?php esc_html_e( 'ИНН', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-avm="s_inn"></p>
							</div>
						</div>
					</div>
				</div>

				<!-- Данные родителя -->
				<div class="fs-modal-accordion__item">
					<button type="button" class="fs-modal-accordion__header" aria-expanded="false" aria-controls="avm-acc-parent">
						<h3><?php esc_html_e( 'Данные родителя', 'fs-lms' ); ?></h3>
						<span class="dashicons dashicons-arrow-down-alt2"></span>
					</button>
					<div class="fs-modal-accordion__body" id="avm-acc-parent" hidden>
						<div class="fs-form-row">
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Фамилия', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-avm="p_last_name"></p>
							</div>
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Имя', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-avm="p_first_name"></p>
							</div>
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Отчество', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-avm="p_middle_name"></p>
							</div>
						</div>
						<div class="fs-form-row">
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Дата рождения', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-avm="p_birth_date"></p>
							</div>
						</div>
						<div class="fs-form-row">
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Email', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-avm="p_email"></p>
							</div>
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Телефон', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-avm="p_phone"></p>
							</div>
						</div>
						<div class="fs-form-row">
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Тип документа', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-avm="p_doc_type"></p>
							</div>
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Серия и номер', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-avm="p_doc_number"></p>
							</div>
						</div>
						<div class="fs-form-row">
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Кем выдан', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-avm="p_doc_issued_by"></p>
							</div>
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Дата выдачи', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-avm="p_doc_issued_date"></p>
							</div>
						</div>
						<div class="fs-form-row">
							<div class="fs-form-group">
								<label><?php esc_html_e( 'ИНН', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-avm="p_inn"></p>
							</div>
						</div>
						<div class="fs-form-group">
							<label><?php esc_html_e( 'Адрес регистрации', 'fs-lms' ); ?></label>
							<p class="fs-view-field" data-avm="p_address"></p>
						</div>
					</div>
				</div>

			</div><!-- /.fs-modal-accordion -->
		</div>

		<div class="fs-lms-modal-footer">
			<button type="button" class="button fs-lms-modal-cancel">
				<?php esc_html_e( 'Закрыть', 'fs-lms' ); ?>
			</button>
		</div>
	</div>
</div>
