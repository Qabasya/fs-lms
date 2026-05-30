<?php
/**
 * Модальное окно просмотра и редактирования заявки на проверке (ReadyForReview).
 * Открывается из userlist-1-applications.php по клику на .js-review-application.
 *
 * @package FS LMS
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
?>

<div id="fs-application-review-modal" class="fs-lms-modal hidden">
	<div class="fs-lms-modal-backdrop"></div>

	<div class="fs-lms-modal-content fs-modal-lg">
		<div class="fs-lms-modal-header">
			<h2 class="fs-lms-modal-title"><?php esc_html_e( 'Заявка на проверке', 'fs-lms' ); ?> <span id="review-modal-id"></span></h2>
			<button type="button" class="fs-lms-modal-close fs-close js-modal-close" aria-label="<?php esc_attr_e( 'Закрыть', 'fs-lms' ); ?>">&times;</button>
		</div>

		<div class="fs-lms-modal-body">
			<form id="fs-application-review-form" autocomplete="off">
				<input type="hidden" name="application_id" value="">

				<div class="fs-modal-accordion">

					<!-- Секция: Данные ученика -->
					<div class="fs-modal-accordion__item">
						<button type="button" class="fs-modal-accordion__header" aria-expanded="true" aria-controls="review-acc-student">
							<h3><?php esc_html_e( 'Данные ученика', 'fs-lms' ); ?></h3>
							<span class="dashicons dashicons-arrow-down-alt2"></span>
						</button>

						<div class="fs-modal-accordion__body" id="review-acc-student">
							<div class="fs-form-row">
								<div class="fs-form-group">
									<label><?php esc_html_e( 'Фамилия', 'fs-lms' ); ?></label>
									<div class="fs-editable-field" data-field="student_last_name">
										<span class="fs-editable-field__display"></span>
										<button type="button" class="fs-editable-field__btn button-link" aria-label="<?php esc_attr_e( 'Редактировать', 'fs-lms' ); ?>">
											<span class="dashicons dashicons-edit"></span>
										</button>
										<input type="text" name="student_last_name" hidden required>
									</div>
								</div>
								<div class="fs-form-group">
									<label><?php esc_html_e( 'Имя', 'fs-lms' ); ?></label>
									<div class="fs-editable-field" data-field="student_first_name">
										<span class="fs-editable-field__display"></span>
										<button type="button" class="fs-editable-field__btn button-link" aria-label="<?php esc_attr_e( 'Редактировать', 'fs-lms' ); ?>">
											<span class="dashicons dashicons-edit"></span>
										</button>
										<input type="text" name="student_first_name" hidden required>
									</div>
								</div>
								<div class="fs-form-group">
									<label><?php esc_html_e( 'Отчество', 'fs-lms' ); ?></label>
									<div class="fs-editable-field" data-field="student_middle_name">
										<span class="fs-editable-field__display"></span>
										<button type="button" class="fs-editable-field__btn button-link" aria-label="<?php esc_attr_e( 'Редактировать', 'fs-lms' ); ?>">
											<span class="dashicons dashicons-edit"></span>
										</button>
										<input type="text" name="student_middle_name" hidden>
									</div>
								</div>
							</div>

							<div class="fs-form-row">
								<div class="fs-form-group">
									<label><?php esc_html_e( 'Дата рождения', 'fs-lms' ); ?></label>
									<div class="fs-editable-field" data-field="student_birth_date">
										<span class="fs-editable-field__display"></span>
										<button type="button" class="fs-editable-field__btn button-link" aria-label="<?php esc_attr_e( 'Редактировать', 'fs-lms' ); ?>">
											<span class="dashicons dashicons-edit"></span>
										</button>
										<input type="date" name="student_birth_date" hidden>
									</div>
								</div>
								<div class="fs-form-group">
									<label><?php esc_html_e( 'Тип документа', 'fs-lms' ); ?></label>
									<div class="fs-editable-field" data-field="student_doc_type">
										<span class="fs-editable-field__display"></span>
										<button type="button" class="fs-editable-field__btn button-link" aria-label="<?php esc_attr_e( 'Редактировать', 'fs-lms' ); ?>">
											<span class="dashicons dashicons-edit"></span>
										</button>
										<input type="text" name="student_doc_type" hidden>
									</div>
								</div>
								<div class="fs-form-group">
									<label><?php esc_html_e( 'Номер документа', 'fs-lms' ); ?></label>
									<div class="fs-editable-field" data-field="student_doc_number">
										<span class="fs-editable-field__display"></span>
										<button type="button" class="fs-editable-field__btn button-link" aria-label="<?php esc_attr_e( 'Редактировать', 'fs-lms' ); ?>">
											<span class="dashicons dashicons-edit"></span>
										</button>
										<input type="text" name="student_doc_number" hidden>
									</div>
								</div>
							</div>

							<div class="fs-form-row">
								<div class="fs-form-group">
									<label><?php esc_html_e( 'ИНН ученика', 'fs-lms' ); ?></label>
									<div class="fs-editable-field" data-field="student_inn">
										<span class="fs-editable-field__display"></span>
										<button type="button" class="fs-editable-field__btn button-link" aria-label="<?php esc_attr_e( 'Редактировать', 'fs-lms' ); ?>">
											<span class="dashicons dashicons-edit"></span>
										</button>
										<input type="text" name="student_inn" hidden>
									</div>
								</div>
							</div>
						</div>
					</div>

					<!-- Секция: Данные родителя -->
					<div class="fs-modal-accordion__item">
						<button type="button" class="fs-modal-accordion__header" aria-expanded="false" aria-controls="review-acc-parent">
							<h3><?php esc_html_e( 'Данные родителя', 'fs-lms' ); ?></h3>
							<span class="dashicons dashicons-arrow-down-alt2"></span>
						</button>

						<div class="fs-modal-accordion__body" id="review-acc-parent" hidden>
							<div class="fs-form-row">
								<div class="fs-form-group">
									<label><?php esc_html_e( 'Фамилия', 'fs-lms' ); ?></label>
									<div class="fs-editable-field" data-field="parent_last_name">
										<span class="fs-editable-field__display"></span>
										<button type="button" class="fs-editable-field__btn button-link" aria-label="<?php esc_attr_e( 'Редактировать', 'fs-lms' ); ?>">
											<span class="dashicons dashicons-edit"></span>
										</button>
										<input type="text" name="parent_last_name" hidden required>
									</div>
								</div>
								<div class="fs-form-group">
									<label><?php esc_html_e( 'Имя', 'fs-lms' ); ?></label>
									<div class="fs-editable-field" data-field="parent_first_name">
										<span class="fs-editable-field__display"></span>
										<button type="button" class="fs-editable-field__btn button-link" aria-label="<?php esc_attr_e( 'Редактировать', 'fs-lms' ); ?>">
											<span class="dashicons dashicons-edit"></span>
										</button>
										<input type="text" name="parent_first_name" hidden required>
									</div>
								</div>
								<div class="fs-form-group">
									<label><?php esc_html_e( 'Отчество', 'fs-lms' ); ?></label>
									<div class="fs-editable-field" data-field="parent_middle_name">
										<span class="fs-editable-field__display"></span>
										<button type="button" class="fs-editable-field__btn button-link" aria-label="<?php esc_attr_e( 'Редактировать', 'fs-lms' ); ?>">
											<span class="dashicons dashicons-edit"></span>
										</button>
										<input type="text" name="parent_middle_name" hidden>
									</div>
								</div>
							</div>

							<div class="fs-form-row">
								<div class="fs-form-group">
									<label><?php esc_html_e( 'Дата рождения', 'fs-lms' ); ?></label>
									<div class="fs-editable-field" data-field="parent_birth_date">
										<span class="fs-editable-field__display"></span>
										<button type="button" class="fs-editable-field__btn button-link" aria-label="<?php esc_attr_e( 'Редактировать', 'fs-lms' ); ?>">
											<span class="dashicons dashicons-edit"></span>
										</button>
										<input type="date" name="parent_birth_date" hidden>
									</div>
								</div>
								<div class="fs-form-group">
									<label><?php esc_html_e( 'Роль', 'fs-lms' ); ?></label>
									<div class="fs-editable-field" data-field="relation_type">
										<span class="fs-editable-field__display"></span>
										<button type="button" class="fs-editable-field__btn button-link" aria-label="<?php esc_attr_e( 'Редактировать', 'fs-lms' ); ?>">
											<span class="dashicons dashicons-edit"></span>
										</button>
										<input type="text" name="relation_type" hidden>
									</div>
								</div>
							</div>

							<div class="fs-form-row">
								<div class="fs-form-group">
									<label><?php esc_html_e( 'Email', 'fs-lms' ); ?></label>
									<div class="fs-editable-field" data-field="parent_email">
										<span class="fs-editable-field__display"></span>
										<button type="button" class="fs-editable-field__btn button-link" aria-label="<?php esc_attr_e( 'Редактировать', 'fs-lms' ); ?>">
											<span class="dashicons dashicons-edit"></span>
										</button>
										<input type="email" name="parent_email" hidden>
									</div>
								</div>
								<div class="fs-form-group">
									<label><?php esc_html_e( 'Телефон', 'fs-lms' ); ?></label>
									<div class="fs-editable-field" data-field="parent_phone">
										<span class="fs-editable-field__display"></span>
										<button type="button" class="fs-editable-field__btn button-link" aria-label="<?php esc_attr_e( 'Редактировать', 'fs-lms' ); ?>">
											<span class="dashicons dashicons-edit"></span>
										</button>
										<input type="tel" name="parent_phone" hidden>
									</div>
								</div>
							</div>

							<div class="fs-form-row">
								<div class="fs-form-group">
									<label><?php esc_html_e( 'Тип документа', 'fs-lms' ); ?></label>
									<div class="fs-editable-field" data-field="parent_doc_type">
										<span class="fs-editable-field__display"></span>
										<button type="button" class="fs-editable-field__btn button-link" aria-label="<?php esc_attr_e( 'Редактировать', 'fs-lms' ); ?>">
											<span class="dashicons dashicons-edit"></span>
										</button>
										<input type="text" name="parent_doc_type" hidden>
									</div>
								</div>
								<div class="fs-form-group">
									<label><?php esc_html_e( 'Серия и номер', 'fs-lms' ); ?></label>
									<div class="fs-editable-field" data-field="parent_doc_number">
										<span class="fs-editable-field__display"></span>
										<button type="button" class="fs-editable-field__btn button-link" aria-label="<?php esc_attr_e( 'Редактировать', 'fs-lms' ); ?>">
											<span class="dashicons dashicons-edit"></span>
										</button>
										<input type="text" name="parent_doc_number" hidden>
									</div>
								</div>
							</div>

							<div class="fs-form-row">
								<div class="fs-form-group">
									<label><?php esc_html_e( 'Кем выдан', 'fs-lms' ); ?></label>
									<div class="fs-editable-field" data-field="parent_doc_issued_by">
										<span class="fs-editable-field__display"></span>
										<button type="button" class="fs-editable-field__btn button-link" aria-label="<?php esc_attr_e( 'Редактировать', 'fs-lms' ); ?>">
											<span class="dashicons dashicons-edit"></span>
										</button>
										<input type="text" name="parent_doc_issued_by" hidden>
									</div>
								</div>
								<div class="fs-form-group">
									<label><?php esc_html_e( 'Дата выдачи', 'fs-lms' ); ?></label>
									<div class="fs-editable-field" data-field="parent_doc_issued_date">
										<span class="fs-editable-field__display"></span>
										<button type="button" class="fs-editable-field__btn button-link" aria-label="<?php esc_attr_e( 'Редактировать', 'fs-lms' ); ?>">
											<span class="dashicons dashicons-edit"></span>
										</button>
										<input type="date" name="parent_doc_issued_date" hidden>
									</div>
								</div>
							</div>

							<div class="fs-form-row">
								<div class="fs-form-group">
									<label><?php esc_html_e( 'ИНН родителя', 'fs-lms' ); ?></label>
									<div class="fs-editable-field" data-field="parent_inn">
										<span class="fs-editable-field__display"></span>
										<button type="button" class="fs-editable-field__btn button-link" aria-label="<?php esc_attr_e( 'Редактировать', 'fs-lms' ); ?>">
											<span class="dashicons dashicons-edit"></span>
										</button>
										<input type="text" name="parent_inn" hidden>
									</div>
								</div>
							</div>

							<div class="fs-form-group">
								<label><?php esc_html_e( 'Адрес регистрации', 'fs-lms' ); ?></label>
								<div class="fs-editable-field" data-field="parent_address">
									<span class="fs-editable-field__display"></span>
									<button type="button" class="fs-editable-field__btn button-link" aria-label="<?php esc_attr_e( 'Редактировать', 'fs-lms' ); ?>">
										<span class="dashicons dashicons-edit"></span>
									</button>
									<input type="text" name="parent_address" hidden>
								</div>
							</div>
						</div>
					</div>

				</div><!-- /.fs-modal-accordion -->

				<div class="fs-lms-modal-footer">
					<button type="button" class="button fs-lms-modal-cancel">
						<?php esc_html_e( 'Закрыть', 'fs-lms' ); ?>
					</button>
					<button type="submit" class="button button-primary" id="review-modal-save-btn">
						<?php esc_html_e( 'Сохранить', 'fs-lms' ); ?>
					</button>
				</div>
			</form>
		</div>
	</div>
</div>