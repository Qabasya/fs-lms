<?php
/**
 * Модальное окно просмотра карточки зачисленного ученика (read-only).
 * Открывается из userlist-2-students.php по клику на .js-view-student.
 *
 * @package FS LMS
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
?>

<div id="fs-student-view-modal" class="fs-lms-modal hidden">
	<div class="fs-lms-modal-backdrop"></div>

	<div class="fs-lms-modal-content fs-modal-lg">
		<div class="fs-lms-modal-header">
			<h2 class="fs-lms-modal-title"><?php esc_html_e( 'Карточка ученика', 'fs-lms' ); ?></h2>
			<button type="button" class="fs-lms-modal-close fs-close js-modal-close" aria-label="<?php esc_attr_e( 'Закрыть', 'fs-lms' ); ?>">&times;</button>
		</div>

		<div class="fs-lms-modal-body">
			<div class="fs-modal-accordion">

				<!-- Зачисление -->
				<div class="fs-modal-accordion__item">
					<button type="button" class="fs-modal-accordion__header" aria-expanded="true" aria-controls="svm-acc-enrollment">
						<h3><?php esc_html_e( 'Зачисление', 'fs-lms' ); ?></h3>
						<span class="dashicons dashicons-arrow-down-alt2"></span>
					</button>
					<div class="fs-modal-accordion__body" id="svm-acc-enrollment">
						<div class="fs-form-row">
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Направление', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-svm="subject"></p>
							</div>
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Группа', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-svm="group"></p>
							</div>
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Преподаватель', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-svm="teacher"></p>
							</div>
						</div>
						<div class="fs-form-row">
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Номер договора', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-svm="contract_no"></p>
							</div>
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Дата договора', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-svm="contract_date"></p>
							</div>
						</div>
						<div class="fs-form-row">
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Номер приказа', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-svm="order_no"></p>
							</div>
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Дата приказа', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-svm="order_date"></p>
							</div>
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Дата зачисления', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-svm="enrolled_at"></p>
							</div>
						</div>
					</div>
				</div>

				<!-- Данные ученика -->
				<div class="fs-modal-accordion__item">
					<button type="button" class="fs-modal-accordion__header" aria-expanded="false" aria-controls="svm-acc-student">
						<h3><?php esc_html_e( 'Данные ученика', 'fs-lms' ); ?></h3>
						<span class="dashicons dashicons-arrow-down-alt2"></span>
					</button>
					<div class="fs-modal-accordion__body" id="svm-acc-student" hidden>
						<div class="fs-form-row">
							<div class="fs-form-group">
								<label><?php esc_html_e( 'ФИО', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-svm="student_full_name"></p>
							</div>
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Дата рождения', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-svm="student_birth_date"></p>
							</div>
						</div>
						<div class="fs-form-row">
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Email', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-svm="student_email"></p>
							</div>
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Телефон', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-svm="student_phone"></p>
							</div>
						</div>
						<div class="fs-form-row">
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Школа', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-svm="student_school"></p>
							</div>
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Класс', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-svm="student_grade"></p>
							</div>
						</div>
						<div class="fs-form-row">
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Тип документа', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-svm="student_doc_type"></p>
							</div>
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Номер документа', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-svm="student_doc_number"></p>
							</div>
							<div class="fs-form-group">
								<label><?php esc_html_e( 'ИНН', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-svm="student_inn"></p>
							</div>
						</div>
					</div>
				</div>

				<!-- Данные родителя -->
				<div class="fs-modal-accordion__item">
					<button type="button" class="fs-modal-accordion__header" aria-expanded="false" aria-controls="svm-acc-guardian">
						<h3><?php esc_html_e( 'Данные родителя', 'fs-lms' ); ?></h3>
						<span class="dashicons dashicons-arrow-down-alt2"></span>
					</button>
					<div class="fs-modal-accordion__body" id="svm-acc-guardian" hidden>
						<div class="fs-form-row">
							<div class="fs-form-group">
								<label><?php esc_html_e( 'ФИО', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-svm="guardian_full_name"></p>
							</div>
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Роль', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-svm="guardian_relation_type"></p>
							</div>
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Дата рождения', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-svm="guardian_birth_date"></p>
							</div>
						</div>
						<div class="fs-form-row">
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Email', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-svm="guardian_email"></p>
							</div>
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Телефон', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-svm="guardian_phone"></p>
							</div>
						</div>
						<div class="fs-form-row">
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Тип документа', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-svm="guardian_doc_type"></p>
							</div>
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Серия и номер', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-svm="guardian_doc_number"></p>
							</div>
						</div>
						<div class="fs-form-row">
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Кем выдан', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-svm="guardian_doc_issued_by"></p>
							</div>
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Дата выдачи', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-svm="guardian_doc_issued_date"></p>
							</div>
						</div>
						<div class="fs-form-row">
							<div class="fs-form-group">
								<label><?php esc_html_e( 'ИНН', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-svm="guardian_inn"></p>
							</div>
						</div>
						<div class="fs-form-group">
							<label><?php esc_html_e( 'Адрес регистрации', 'fs-lms' ); ?></label>
							<p class="fs-view-field" data-svm="guardian_address"></p>
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
