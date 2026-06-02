<?php
/**
 * Модальное окно просмотра карточки person с маскированными PII.
 * Чувствительные поля открываются через AJAX (ajaxRevealPiiField), что логируется.
 * Открывается из userlist-2-students.php и userlist-3-parents.php по клику на .js-view-person.
 *
 * @package FS LMS
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
?>

<div id="fs-person-view-modal" class="fs-lms-modal hidden">
	<div class="fs-lms-modal-backdrop"></div>

	<div class="fs-lms-modal-content fs-modal-lg">
		<div class="fs-lms-modal-header">
			<h2 class="fs-lms-modal-title" id="pvm-title"></h2>
			<button type="button" class="fs-lms-modal-close fs-close js-modal-close" aria-label="<?php esc_attr_e( 'Закрыть', 'fs-lms' ); ?>">&times;</button>
		</div>

		<div class="fs-lms-modal-body">
			<div class="fs-modal-accordion">

				<!-- Личные данные -->
				<div class="fs-modal-accordion__item">
					<button type="button" class="fs-modal-accordion__header" aria-expanded="true" aria-controls="pvm-acc-data">
						<h3><?php esc_html_e( 'Личные данные', 'fs-lms' ); ?></h3>
						<span class="dashicons dashicons-arrow-down-alt2"></span>
					</button>
					<div class="fs-modal-accordion__body" id="pvm-acc-data">

						<div class="fs-form-row">
							<div class="fs-form-group">
								<label><?php esc_html_e( 'ФИО', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-pvm="display_name"></p>
							</div>
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Email', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-pvm="email"></p>
							</div>
						</div>

						<!-- Поля ученика -->
						<div class="pvm-student-fields" hidden>
							<div class="fs-form-row">
								<div class="fs-form-group">
									<label><?php esc_html_e( 'Школа', 'fs-lms' ); ?></label>
									<p class="fs-view-field" data-pvm="school"></p>
								</div>
								<div class="fs-form-group">
									<label><?php esc_html_e( 'Класс', 'fs-lms' ); ?></label>
									<p class="fs-view-field" data-pvm="grade"></p>
								</div>
								<div class="fs-form-group">
									<label><?php esc_html_e( 'Дата рождения', 'fs-lms' ); ?></label>
									<p class="fs-view-field" data-pvm="birth_date"></p>
								</div>
							</div>
						</div>

						<!-- Поля родителя -->
						<div class="pvm-parent-fields" hidden>
							<div class="fs-form-row">
								<div class="fs-form-group">
									<label><?php esc_html_e( 'Роль', 'fs-lms' ); ?></label>
									<p class="fs-view-field" data-pvm="relation_type"></p>
								</div>
								<div class="fs-form-group">
									<label><?php esc_html_e( 'Дата рождения', 'fs-lms' ); ?></label>
									<p class="fs-view-field" data-pvm="birth_date"></p>
								</div>
							</div>
						</div>

						<!-- Чувствительные поля — всегда маскированы -->
						<div class="fs-form-row">
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Телефон', 'fs-lms' ); ?></label>
								<div class="fs-pii-field" data-field="phone">
									<span class="fs-pii-field__masked">••••••</span>
									<span class="fs-pii-field__revealed" hidden></span>
									<button type="button" class="button-link js-pvm-reveal" data-field="phone">
										<span class="dashicons dashicons-visibility"></span>
										<?php esc_html_e( 'Показать', 'fs-lms' ); ?>
									</button>
								</div>
							</div>
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Документ', 'fs-lms' ); ?></label>
								<div class="fs-pii-field" data-field="doc_number">
									<span class="fs-pii-field__masked">••••••</span>
									<span class="fs-pii-field__revealed" hidden></span>
									<button type="button" class="button-link js-pvm-reveal" data-field="doc_number">
										<span class="dashicons dashicons-visibility"></span>
										<?php esc_html_e( 'Показать', 'fs-lms' ); ?>
									</button>
								</div>
							</div>
						</div>

						<div class="fs-form-row">
							<div class="fs-form-group">
								<label><?php esc_html_e( 'ИНН', 'fs-lms' ); ?></label>
								<div class="fs-pii-field" data-field="inn">
									<span class="fs-pii-field__masked">••••••</span>
									<span class="fs-pii-field__revealed" hidden></span>
									<button type="button" class="button-link js-pvm-reveal" data-field="inn">
										<span class="dashicons dashicons-visibility"></span>
										<?php esc_html_e( 'Показать', 'fs-lms' ); ?>
									</button>
								</div>
							</div>
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Адрес', 'fs-lms' ); ?></label>
								<div class="fs-pii-field" data-field="address">
									<span class="fs-pii-field__masked">••••••</span>
									<span class="fs-pii-field__revealed" hidden></span>
									<button type="button" class="button-link js-pvm-reveal" data-field="address">
										<span class="dashicons dashicons-visibility"></span>
										<?php esc_html_e( 'Показать', 'fs-lms' ); ?>
									</button>
								</div>
							</div>
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
