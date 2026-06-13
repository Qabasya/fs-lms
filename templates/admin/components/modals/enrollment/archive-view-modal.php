<?php
/**
 * Модальное окно просмотра архивного зачисления (read-only).
 * Открывается из userlist-5-archive.php по клику на .js-view-archive.
 *
 * @package FS LMS
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
?>

<div id="fs-archive-view-modal" class="fs-lms-modal hidden">
	<div class="fs-lms-modal-backdrop"></div>

	<div class="fs-lms-modal-content fs-modal-lg">
		<div class="fs-lms-modal-header">
			<h2 class="fs-lms-modal-title"><?php esc_html_e( 'Архивное зачисление', 'fs-lms' ); ?></h2>
			<button type="button" class="fs-lms-modal-close fs-close js-modal-close" aria-label="<?php esc_attr_e( 'Закрыть', 'fs-lms' ); ?>">&times;</button>
		</div>

		<div class="fs-lms-modal-body">
			<div class="fs-modal-accordion">

				<!-- Данные ребёнка -->
				<div class="fs-modal-accordion__item">
					<button type="button" class="fs-modal-accordion__header" aria-expanded="true" aria-controls="avm-arc-student">
						<h3><?php esc_html_e( 'Данные ребёнка', 'fs-lms' ); ?></h3>
						<span class="dashicons dashicons-arrow-down-alt2"></span>
					</button>
					<div class="fs-modal-accordion__body" id="avm-arc-student">
						<div class="fs-form-row">
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Фамилия', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-arc="s_last_name"></p>
							</div>
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Имя', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-arc="s_first_name"></p>
							</div>
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Отчество', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-arc="s_middle_name"></p>
							</div>
						</div>
						<div class="fs-form-row">
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Дата рождения', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-arc="s_birth_date"></p>
							</div>
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Email', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-arc="s_email"></p>
							</div>
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Телефон', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-arc="s_phone"></p>
							</div>
						</div>
						<div class="fs-form-row">
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Школа', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-arc="s_school"></p>
							</div>
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Класс', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-arc="s_grade"></p>
							</div>
						</div>
						<div class="fs-form-row">
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Тип документа', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-arc="s_doc_type"></p>
							</div>
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Номер документа', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-arc="s_doc_number"></p>
							</div>
							<div class="fs-form-group">
								<label><?php esc_html_e( 'ИНН', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-arc="s_inn"></p>
							</div>
						</div>
					</div>
				</div>

				<!-- Данные родителя -->
				<div class="fs-modal-accordion__item">
					<button type="button" class="fs-modal-accordion__header" aria-expanded="false" aria-controls="avm-arc-parent">
						<h3><?php esc_html_e( 'Данные родителя', 'fs-lms' ); ?></h3>
						<span class="dashicons dashicons-arrow-down-alt2"></span>
					</button>
					<div class="fs-modal-accordion__body" id="avm-arc-parent" hidden>
						<div class="fs-form-row">
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Фамилия', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-arc="g_last_name"></p>
							</div>
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Имя', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-arc="g_first_name"></p>
							</div>
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Отчество', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-arc="g_middle_name"></p>
							</div>
						</div>
						<div class="fs-form-row">
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Дата рождения', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-arc="g_birth_date"></p>
							</div>
						</div>
						<div class="fs-form-row">
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Email', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-arc="g_email"></p>
							</div>
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Телефон', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-arc="g_phone"></p>
							</div>
						</div>
						<div class="fs-form-row">
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Тип документа', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-arc="g_doc_type"></p>
							</div>
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Серия и номер', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-arc="g_doc_number"></p>
							</div>
						</div>
						<div class="fs-form-row">
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Кем выдан', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-arc="g_doc_issued_by"></p>
							</div>
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Дата выдачи', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-arc="g_doc_issued_date"></p>
							</div>
						</div>
						<div class="fs-form-row">
							<div class="fs-form-group">
								<label><?php esc_html_e( 'ИНН', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-arc="g_inn"></p>
							</div>
						</div>
						<div class="fs-form-group">
							<label><?php esc_html_e( 'Адрес регистрации', 'fs-lms' ); ?></label>
							<p class="fs-view-field" data-arc="g_address"></p>
						</div>
					</div>
				</div>

				<!-- Данные о зачислении -->
				<div class="fs-modal-accordion__item">
					<button type="button" class="fs-modal-accordion__header" aria-expanded="false" aria-controls="avm-arc-enrollment">
						<h3><?php esc_html_e( 'Данные о зачислении', 'fs-lms' ); ?></h3>
						<span class="dashicons dashicons-arrow-down-alt2"></span>
					</button>
					<div class="fs-modal-accordion__body" id="avm-arc-enrollment" hidden>
						<div class="fs-form-row">
							<div class="fs-form-group">
								<label><?php esc_html_e( '№ договора', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-arc="contract_no"></p>
							</div>
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Дата договора', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-arc="contract_date"></p>
							</div>
						</div>
						<div class="fs-form-row">
							<div class="fs-form-group">
								<label><?php esc_html_e( '№ приказа', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-arc="order_no"></p>
							</div>
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Дата приказа', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-arc="order_date"></p>
							</div>
						</div>
						<div class="fs-form-row">
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Предмет', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-arc="subject"></p>
							</div>
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Группа', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-arc="group"></p>
							</div>
						</div>
						<div class="fs-form-row">
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Статус', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-arc="status"></p>
							</div>
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Дата завершения', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-arc="terminated_at"></p>
							</div>
						</div>
						<div class="fs-form-group">
							<label><?php esc_html_e( 'Причина отчисления', 'fs-lms' ); ?></label>
							<p class="fs-view-field" data-arc="terminated_reason"></p>
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
