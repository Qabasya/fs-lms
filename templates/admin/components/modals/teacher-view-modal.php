<?php
/**
 * Модальное окно просмотра карточки преподавателя (read-only).
 * Открывается из userlist-4-teachers.php по клику на .js-view-teacher.
 *
 * @package FS LMS
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
?>

<div id="fs-teacher-view-modal" class="fs-lms-modal hidden">
	<div class="fs-lms-modal-backdrop"></div>

	<div class="fs-lms-modal-content">
		<div class="fs-lms-modal-header">
			<h2 class="fs-lms-modal-title"><?php esc_html_e( 'Карточка преподавателя', 'fs-lms' ); ?></h2>
			<button type="button" class="fs-lms-modal-close fs-close js-modal-close" aria-label="<?php esc_attr_e( 'Закрыть', 'fs-lms' ); ?>">&times;</button>
		</div>

		<div class="fs-lms-modal-body">
			<div class="fs-form-row">
				<div class="fs-form-group">
					<label><?php esc_html_e( 'ФИО', 'fs-lms' ); ?></label>
					<p class="fs-view-field" data-tvm="full_name"></p>
				</div>
				<div class="fs-form-group">
					<label><?php esc_html_e( 'Email', 'fs-lms' ); ?></label>
					<p class="fs-view-field" data-tvm="email"></p>
				</div>
			</div>
			<div class="fs-form-group">
				<label><?php esc_html_e( 'Предметы', 'fs-lms' ); ?></label>
				<p class="fs-view-field" data-tvm="subjects"></p>
			</div>
			<div class="fs-form-group">
				<label><?php esc_html_e( 'Группы', 'fs-lms' ); ?></label>
				<p class="fs-view-field" data-tvm="groups"></p>
			</div>
		</div>

		<div class="fs-lms-modal-footer">
			<button type="button" class="button fs-lms-modal-cancel">
				<?php esc_html_e( 'Закрыть', 'fs-lms' ); ?>
			</button>
		</div>
	</div>
</div>
