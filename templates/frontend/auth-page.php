<?php
/**
 * Боевой шаблон страницы авторизации (Sign-In)
 *
 * @var array  $providers
 * @var string $register_url
 * @var string $lost_pass_url
 */
?>
<div class="fs-auth-card">
	<h2 class="fs-auth-card__title">Войти в личный кабинет</h2>

	<form name="loginform" id="loginform" action="<?php echo esc_url( site_url( 'wp-login.php', 'login_post' ) ); ?>" method="post">

		<div class="fs-auth-card__field-group">
			<span class="dashicons dashicons-email"></span>
			<input type="text" name="log" id="user_login" placeholder="Email или логин" required>
		</div>

		<div class="fs-auth-card__field-group fs-lms-secret-field">
			<span class="dashicons dashicons-admin-network"></span>
			<input type="password" name="pwd" id="user_pass" placeholder="Пароль" required>
			<button type="button" class="js-toggle-secret" aria-label="Показать пароль">
				<span class="dashicons dashicons-visibility"></span>
			</button>
		</div>

		<div class="fs-auth-card__meta">
			<a href="<?php echo esc_url( $lost_pass_url ); ?>">Забыли пароль?</a>
		</div>

		<button type="submit" name="wp-submit" id="wp-submit" class="button button-primary fs-auth-card__submit">
			Войти
		</button>

		<input type="hidden" name="redirect_to" value="<?php echo esc_url( apply_filters( 'lms_auth_redirect_url', home_url(), null ) ); ?>">
	</form>

	<div class="fs-auth-card__switch">
		Еще нет аккаунта? <a href="<?php echo esc_url( $register_url ); ?>">Зарегистрироваться</a>
	</div>

	<?php if ( ! empty( $providers ) ) : ?>
		<div class="fs-auth-card__divider">
			<span>или</span>
		</div>

		<div class="fs-auth-card__socials">
			<?php
			foreach ( $providers as $provider ) :
				$provider_id = esc_attr( $provider['id'] );
				?>
				<a href="<?php echo esc_url( $provider['url'] ); ?>" class="fs-auth-card__btn-social">
					<span class="fs-social-icon fs-social-icon--<?php echo $provider_id; ?>"></span>
					Продолжить с <?php echo esc_html( $provider['label'] ); ?>
				</a>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<div class="fs-auth-card__footer">
		Входя в систему, вы соглашаетесь с нашими <br>
		<a href="#">Условиями использования</a> и <a href="#">Политикой конфиденциальности</a>.
	</div>
</div>