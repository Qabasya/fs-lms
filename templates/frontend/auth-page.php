<?php
/**
 * Шаблон страницы авторизации (Sign-In)
 *
 * @package FS LMS
 * @var string $error_message Уведомление после неудачного входа; '' — не показываем
 * @var string $prefill_login Логин из неудачной попытки
 * @var string $consent_url   URL согласия на обработку ПДн; '' — сноску не показываем
 */
?>
<div class="fs-auth-card">
	<h2 class="fs-auth-card__title">Войти в личный кабинет</h2>

	<?php if ( '' !== $error_message ) : ?>
		<div class="fs-auth-card__error" role="alert">
			<?php echo esc_html( $error_message ); ?>
		</div>
	<?php endif; ?>

	<div class="fs-auth-card__error" id="fs-login-captcha-error" role="alert" hidden></div>

	<!-- Стандартная форма авторизации WordPress -->
	<form name="loginform" id="loginform" action="<?php echo esc_url( site_url( 'wp-login.php', 'login_post' ) ); ?>" method="post">

		<!-- Маркер: вход инициирован с кастомной страницы (для wp_login_failed) -->
		<input type="hidden" name="fs_lms_login" value="1">

		<div class="fs-auth-card__field-group">
			<span class="dashicons dashicons-email"></span>
			<input type="text" name="log" id="user_login" placeholder="Email или логин" value="<?php echo esc_attr( $prefill_login ); ?>" required>
		</div>

		<div class="fs-auth-card__field-group fs-lms-secret-field">
			<span class="dashicons dashicons-admin-network"></span>
			<input type="password" name="pwd" id="user_pass" placeholder="Пароль" required>
			<!-- Кнопка показа/скрытия пароля (обработка через JS) -->
			<button type="button" class="js-toggle-secret" aria-label="Показать пароль">
				<span class="dashicons dashicons-visibility"></span>
			</button>
		</div>

		<?php // Слот невидимой капчи (модуль SmartCaptcha); токен пишет login-form.js. ?>
		<div id="fs-captcha-slot" class="fs-auth-card__captcha" role="region" aria-label="Проверка безопасности"></div>
		<input type="hidden" name="captcha_token" value="">

		<button type="submit" name="wp-submit" id="wp-submit" class="fs-auth-card__submit">
			Войти
		</button>

		<!-- Редирект после успешного входа (фильтр для кастомизации) -->
		<input type="hidden" name="redirect_to" value="<?php echo esc_url( apply_filters( 'lms_auth_redirect_url', home_url(), null ) ); ?>">
	</form>

	<?php // Сноска — только когда согласие на обработку ПДн заведено: ссылка в никуда хуже её отсутствия. ?>
	<?php if ( '' !== $consent_url ) : ?>
		<div class="fs-auth-card__footer">
			Входя в систему, вы соглашаетесь с<br>
			<a href="<?php echo esc_url( $consent_url ); ?>">политикой обработки персональных данных</a>.
		</div>
	<?php endif; ?>
</div>
