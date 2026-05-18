<?php
/**
 * Шаблон боевой страницы авторизации
 *
 * @var array  $providers
 * @var string $register_url
 * @var string $lost_pass_url
 */
// TODO: заменить ссылки

?>
<div class="fs-lms-auth-container">
	<div class="fs-lms-auth-card">
		<h2 class="fs-lms-auth-title">Войти в личный кабинет</h2>

		<form name="loginform" id="loginform" action="<?php echo esc_url( site_url( 'wp-login.php', 'login_post' ) ); ?>" method="post">

			<div class="fs-lms-form-group">
				<span class="fs-lms-form-icon dashicons dashicons-email"></span>
				<input type="text" name="log" id="user_login" class="fs-lms-input" placeholder="Введите ваш email или логин" required>
			</div>

			<div class="fs-lms-form-group fs-lms-secret-field">
				<span class="fs-lms-form-icon dashicons dashicons-lock"></span>
				<input type="password" name="pwd" id="user_pass" class="fs-lms-input regular-text" placeholder="Введите ваш пароль" required>
				<button type="button" class="fs-lms-toggle-password js-toggle-secret" aria-label="Показать пароль">
					<span class="dashicons dashicons-visibility"></span>
				</button>
			</div>

			<div class="fs-lms-auth-meta">
				<a href="<?php echo esc_url( $lost_pass_url ); ?>" class="fs-lms-link-forgot">Забыли пароль?</a>
			</div>

			<button type="submit" name="wp-submit" id="wp-submit" class="fs-lms-btn-submit">Войти</button>

			<input type="hidden" name="redirect_to" value="<?php echo esc_url( apply_filters( 'lms_auth_redirect_url', home_url(), null ) ); ?>">
		</form>

		<div class="fs-lms-auth-switch">
			У вас нет аккаунта? <a href="<?php echo esc_url( $register_url ); ?>">Зарегистрироваться</a>
		</div>

		<?php if ( ! empty( $providers ) ) : ?>
			<div class="fs-lms-auth-divider">
				<span>или</span>
			</div>

			<div class="fs-lms-social-actions">
				<?php
				foreach ( $providers as $provider ) :
					$provider_id = esc_attr( $provider['id'] );
					?>
					<a href="<?php echo esc_url( $provider['url'] ); ?>" class="fs-lms-btn-social fs-lms-btn-<?php echo $provider_id; ?>">
						<span class="fs-lms-social-icon icon-<?php echo $provider_id; ?>"></span>
						Продолжить с <?php echo esc_html( $provider['label'] ); ?>
					</a>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

	</div>

	<div class="fs-lms-auth-footer">
		Входя в систему, вы соглашаетесь с нашими <br>
		<a href="#">Пользовательскими условиями</a> и <a href="#">Политикой конфиденциальности</a>.
	</div>
</div>