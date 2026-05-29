<?php
/**
 * Шаблон страницы подачи заявки на зачисление (/lms/apply)
 * TODO: прикрутить стили
 * JS-переменные: fs_lms_apply_vars (локализуются в Enqueue::enqueue_frontend_assets)
 *
 * @package FS LMS
 */

use Inc\Enums\Nonce;
use Inc\Services\ThemeCompatService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

ThemeCompatService::header();
?>

<main class="fs-lms-apply-page">
	<div class="fs-apply-card">
		<h2 class="fs-apply-card__title"><?php esc_html_e( 'Подать заявку на обучение', 'fs-lms' ); ?></h2>

		<!-- ЭТАП 1: Форма ввода данных -->
		<div id="apply-form" class="fs-apply-card__step fs-apply-card__step--active">
			<form name="fs_lms_apply_form" id="fs-lms-apply-form" method="post" novalidate autocomplete="off">

				<?php wp_nonce_field( Nonce::Apply->value, 'security' ); ?>

				<div class="fs-apply-card__field-group">
					<span class="dashicons dashicons-admin-users"></span>
					<input type="text" name="full_name" id="fs_full_name"
						placeholder="<?php esc_attr_e( 'ФИО', 'fs-lms' ); ?>" required>
				</div>

				<div class="fs-apply-card__field-group">
					<span class="dashicons dashicons-email"></span>
					<input type="email" name="email" id="fs_email"
						placeholder="<?php esc_attr_e( 'Email', 'fs-lms' ); ?>" required>
				</div>

				<div class="fs-apply-card__field-group">
					<span class="dashicons dashicons-id"></span>
					<input type="text" name="username" id="fs_username"
						placeholder="<?php esc_attr_e( 'Логин', 'fs-lms' ); ?>" required autocomplete="username">
				</div>

				<div class="fs-apply-card__field-group fs-lms-secret-field">
					<span class="dashicons dashicons-admin-network"></span>
					<input type="password" name="password" id="fs_password"
						placeholder="<?php esc_attr_e( 'Пароль', 'fs-lms' ); ?>" required autocomplete="new-password">
					<button type="button" class="js-toggle-secret"
						aria-label="<?php esc_attr_e( 'Показать пароль', 'fs-lms' ); ?>">
						<span class="dashicons dashicons-visibility"></span>
					</button>
				</div>

				<div class="fs-apply-card__field-group">
					<span class="dashicons dashicons-calendar-alt"></span>
					<input type="date" name="birth_date" id="fs_birth_date" required
						aria-label="<?php esc_attr_e( 'Дата рождения', 'fs-lms' ); ?>">
				</div>

				<div class="fs-apply-card__field-group">
					<span class="dashicons dashicons-welcome-learn-more"></span>
					<input type="text" name="school" id="fs_school"
						placeholder="<?php esc_attr_e( 'Школа', 'fs-lms' ); ?>">
				</div>

				<div class="fs-apply-card__field-group">
					<span class="dashicons dashicons-list-view"></span>
					<select name="grade" id="fs_grade" required>
						<option value=""><?php esc_html_e( 'Класс', 'fs-lms' ); ?></option>
						<?php for ( $i = 1; $i <= 11; $i++ ) : ?>
							<option value="<?php echo esc_attr( (string) $i ); ?>">
								<?php echo esc_html( $i . ' класс' ); ?>
							</option>
						<?php endfor; ?>
					</select>
				</div>

				<!-- Слот капчи — заполняется JS -->
				<div id="fs-captcha-slot" class="fs-apply-card__captcha"></div>

				<button type="submit" id="fs-apply-submit"
					class="button button-primary button-large fs-apply-card__submit">
					<?php esc_html_e( 'Подать заявку', 'fs-lms' ); ?>
				</button>
			</form>
		</div>

		<!-- ЭТАП 2: OTP-подтверждение -->
		<div id="otp-step" class="fs-apply-card__step">

			<div class="js-otp-input-block">
				<p class="fs-apply-card__description">
					<?php esc_html_e( 'Код отправлен на', 'fs-lms' ); ?>
					<strong class="js-masked-email"></strong>
				</p>

				<form name="fs_lms_otp_form" id="fs-lms-otp-form" method="post" novalidate>
					<div class="fs-apply-card__field-group fs-apply-card__field-group--otp">
						<span class="dashicons dashicons-shield"></span>
						<input type="text" name="otp_code" id="fs_otp_code"
							placeholder="000000" inputmode="numeric" maxlength="6"
							required autocomplete="one-time-code">
					</div>

					<button type="submit" id="fs-otp-submit"
						class="button button-primary button-large fs-apply-card__submit">
						<?php esc_html_e( 'Подтвердить', 'fs-lms' ); ?>
					</button>

					<div class="fs-apply-card__resend">
						<button type="button" id="fs-resend-otp-btn"
							class="button-link js-resend-otp" disabled>
							<?php esc_html_e( 'Отправить ещё раз', 'fs-lms' ); ?>
							<span class="js-otp-countdown">(60)</span>
						</button>
					</div>
				</form>
			</div>

			<!-- Показывается вместо формы OTP после успешной отправки -->
			<div class="js-otp-success-block fs-apply-card__success" style="display:none">
				<span class="dashicons dashicons-yes-alt fs-apply-card__success-icon"></span>
				<p class="fs-apply-card__success-title">
					<?php esc_html_e( 'Заявка успешно отправлена!', 'fs-lms' ); ?>
				</p>
			</div>
		</div>
	</div>
</main>

<?php ThemeCompatService::footer(); ?>