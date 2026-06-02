<?php
/**
 * Модальное окно просмотра карточки родителя/представителя (read-only).
 * Открывается из userlist-3-parents.php по клику на .js-view-parent.
 *
 * @package FS LMS
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
?>

<div id="fs-parent-view-modal" class="fs-lms-modal hidden">
	<div class="fs-lms-modal-backdrop"></div>

	<div class="fs-lms-modal-content fs-modal-lg">
		<div class="fs-lms-modal-header">
			<h2 class="fs-lms-modal-title"><?php esc_html_e( 'Карточка родителя', 'fs-lms' ); ?></h2>
			<button type="button" class="fs-lms-modal-close fs-close js-modal-close" aria-label="<?php esc_attr_e( 'Закрыть', 'fs-lms' ); ?>">&times;</button>
		</div>

		<div class="fs-lms-modal-body">
			<div class="fs-modal-accordion">

				<!-- Данные родителя -->
				<div class="fs-modal-accordion__item">
					<button type="button" class="fs-modal-accordion__header" aria-expanded="true" aria-controls="pvm-acc-parent">
						<h3><?php esc_html_e( 'Данные родителя', 'fs-lms' ); ?></h3>
						<span class="dashicons dashicons-arrow-down-alt2"></span>
					</button>
					<div class="fs-modal-accordion__body" id="pvm-acc-parent">
						<div class="fs-form-row">
							<div class="fs-form-group">
								<label><?php esc_html_e( 'ФИО', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-pvm="full_name"></p>
							</div>
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Роль', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-pvm="relation_type"></p>
							</div>
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Дата рождения', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-pvm="birth_date"></p>
							</div>
						</div>
						<div class="fs-form-row">
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Email', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-pvm="email"></p>
							</div>
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Телефон', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-pvm="phone"></p>
							</div>
						</div>
						<div class="fs-form-row">
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Тип документа', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-pvm="doc_type"></p>
							</div>
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Серия и номер', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-pvm="doc_number"></p>
							</div>
						</div>
						<div class="fs-form-row">
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Кем выдан', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-pvm="doc_issued_by"></p>
							</div>
							<div class="fs-form-group">
								<label><?php esc_html_e( 'Дата выдачи', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-pvm="doc_issued_date"></p>
							</div>
						</div>
						<div class="fs-form-row">
							<div class="fs-form-group">
								<label><?php esc_html_e( 'ИНН', 'fs-lms' ); ?></label>
								<p class="fs-view-field" data-pvm="inn"></p>
							</div>
						</div>
						<div class="fs-form-group">
							<label><?php esc_html_e( 'Адрес регистрации', 'fs-lms' ); ?></label>
							<p class="fs-view-field" data-pvm="address"></p>
						</div>
					</div>
				</div>

				<!-- Дети -->
				<div class="fs-modal-accordion__item">
					<button type="button" class="fs-modal-accordion__header" aria-expanded="false" aria-controls="pvm-acc-children">
						<h3><?php esc_html_e( 'Дети', 'fs-lms' ); ?></h3>
						<span class="dashicons dashicons-arrow-down-alt2"></span>
					</button>
					<div class="fs-modal-accordion__body" id="pvm-acc-children" hidden>
						<p class="fs-view-field" data-pvm="children"></p>
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
