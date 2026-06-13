<?php
/**
 * Модальное окно просмотра и редактирования данных заявки.
 * Открывается из userlist-1-applications.php по клику на .js-edit-application.
 *
 * @package FS LMS
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
?>

<div id="fs-application-modal" class="fs-lms-modal hidden">
	<div class="fs-lms-modal-backdrop"></div>

	<div class="fs-lms-modal-content fs-modal-md">
		<div class="fs-lms-modal-header">
			<h2 class="fs-lms-modal-title"><?php esc_html_e( 'Заявка', 'fs-lms' ); ?> <span id="app-modal-id"></span></h2>
			<button type="button" class="fs-lms-modal-close fs-close js-modal-close" aria-label="<?php esc_attr_e( 'Закрыть', 'fs-lms' ); ?>">&times;</button>
		</div>

		<div class="fs-lms-modal-body">
			<form id="fs-application-modal-form" autocomplete="off">
				<input type="hidden" name="application_id" value="">

				<div class="fs-form-row">
					<div class="fs-form-group">
						<label><?php esc_html_e( 'Фамилия', 'fs-lms' ); ?></label>
						<div class="fs-editable-field" data-field="last_name">
							<span class="fs-editable-field__display"></span>
							<button type="button" class="fs-editable-field__btn button-link" aria-label="<?php esc_attr_e( 'Редактировать', 'fs-lms' ); ?>">
								<span class="dashicons dashicons-edit"></span>
							</button>
							<input type="text" name="last_name" hidden required>
						</div>
					</div>

					<div class="fs-form-group">
						<label><?php esc_html_e( 'Имя', 'fs-lms' ); ?></label>
						<div class="fs-editable-field" data-field="first_name">
							<span class="fs-editable-field__display"></span>
							<button type="button" class="fs-editable-field__btn button-link" aria-label="<?php esc_attr_e( 'Редактировать', 'fs-lms' ); ?>">
								<span class="dashicons dashicons-edit"></span>
							</button>
							<input type="text" name="first_name" hidden required>
						</div>
					</div>

					<div class="fs-form-group">
						<label><?php esc_html_e( 'Отчество', 'fs-lms' ); ?></label>
						<div class="fs-editable-field" data-field="middle_name">
							<span class="fs-editable-field__display"></span>
							<button type="button" class="fs-editable-field__btn button-link" aria-label="<?php esc_attr_e( 'Редактировать', 'fs-lms' ); ?>">
								<span class="dashicons dashicons-edit"></span>
							</button>
							<input type="text" name="middle_name" hidden>
						</div>
					</div>
				</div>

				<div class="fs-form-row">
					<div class="fs-form-group">
						<label><?php esc_html_e( 'Логин', 'fs-lms' ); ?></label>
						<div class="fs-editable-field" data-field="login">
							<span class="fs-editable-field__display"></span>
							<button type="button" class="fs-editable-field__btn button-link" aria-label="<?php esc_attr_e( 'Редактировать', 'fs-lms' ); ?>">
								<span class="dashicons dashicons-edit"></span>
							</button>
							<input type="text" name="login" hidden>
						</div>
					</div>

					<div class="fs-form-group">
						<label><?php esc_html_e( 'Пароль', 'fs-lms' ); ?></label>
						<div class="fs-editable-field" data-field="password">
							<span class="fs-editable-field__display"></span>
							<button type="button" class="fs-editable-field__btn button-link" aria-label="<?php esc_attr_e( 'Редактировать', 'fs-lms' ); ?>">
								<span class="dashicons dashicons-edit"></span>
							</button>
							<input type="text" name="password" hidden>
						</div>
					</div>
				</div>


				<div class="fs-form-row">
					<div class="fs-form-group">
						<label><?php esc_html_e( 'Email', 'fs-lms' ); ?></label>
						<div class="fs-editable-field" data-field="email">
							<span class="fs-editable-field__display"></span>
							<button type="button" class="fs-editable-field__btn button-link" aria-label="<?php esc_attr_e( 'Редактировать', 'fs-lms' ); ?>">
								<span class="dashicons dashicons-edit"></span>
							</button>
							<input type="email" name="email" hidden required>
						</div>
					</div>

					<div class="fs-form-group">
						<label><?php esc_html_e( 'Телефон', 'fs-lms' ); ?></label>
						<div class="fs-editable-field" data-field="phone">
							<span class="fs-editable-field__display"></span>
							<button type="button" class="fs-editable-field__btn button-link" aria-label="<?php esc_attr_e( 'Редактировать', 'fs-lms' ); ?>">
								<span class="dashicons dashicons-edit"></span>
							</button>
							<input type="tel" name="phone" hidden required>
						</div>
					</div>
				</div>

				<div class="fs-form-row">
					<div class="fs-form-group">
						<label><?php esc_html_e( 'Дата рождения', 'fs-lms' ); ?></label>
						<div class="fs-editable-field" data-field="birth_date">
							<span class="fs-editable-field__display"></span>
							<button type="button" class="fs-editable-field__btn button-link" aria-label="<?php esc_attr_e( 'Редактировать', 'fs-lms' ); ?>">
								<span class="dashicons dashicons-edit"></span>
							</button>
							<input type="date" name="birth_date" hidden required>
						</div>
					</div>

					<div class="fs-form-group">
						<label><?php esc_html_e( 'Класс', 'fs-lms' ); ?></label>
						<div class="fs-editable-field" data-field="grade">
							<span class="fs-editable-field__display"></span>
							<button type="button" class="fs-editable-field__btn button-link" aria-label="<?php esc_attr_e( 'Редактировать', 'fs-lms' ); ?>">
								<span class="dashicons dashicons-edit"></span>
							</button>
							<select name="grade" hidden required>
								<option value=""><?php esc_html_e( 'Класс', 'fs-lms' ); ?></option>
								<?php for ( $i = 1; $i <= 11; $i++ ) : ?>
									<option value="<?php echo esc_attr( (string) $i ); ?>">
										<?php echo esc_html( $i . ' класс' ); ?>
									</option>
								<?php endfor; ?>
							</select>
						</div>
					</div>

					<div class="fs-form-group">
						<label><?php esc_html_e( 'Школа', 'fs-lms' ); ?></label>
						<div class="fs-editable-field" data-field="school">
							<span class="fs-editable-field__display"></span>
							<button type="button" class="fs-editable-field__btn button-link" aria-label="<?php esc_attr_e( 'Редактировать', 'fs-lms' ); ?>">
								<span class="dashicons dashicons-edit"></span>
							</button>
							<input type="text" name="school" hidden>
						</div>
					</div>
				</div>

				<div class="fs-lms-modal-footer">
					<button type="button" class="button fs-lms-modal-cancel">
						<?php esc_html_e( 'Закрыть', 'fs-lms' ); ?>
					</button>
					<button type="submit" class="button button-primary" id="app-modal-save-btn">
						<?php esc_html_e( 'Сохранить', 'fs-lms' ); ?>
					</button>
				</div>
			</form>
		</div>
	</div>
</div>