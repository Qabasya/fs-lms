<?php
/**
 * Шаблон страницы авторизации (Sign-In)
 *
 * @package FS LMS
 * @var string $lost_pass_url URL восстановления пароля
 * @var string $consent_url   URL согласия на обработку ПДн; '' — сноску не показываем
 */

// Флаг неудачного входа и введённый логин приходят редиректом из wp_login_failed.
$login_failed  = isset( $_GET['login'] ) && 'failed' === $_GET['login']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$prefill_login = isset( $_GET['fs_user'] ) ? sanitize_text_field( wp_unslash( $_GET['fs_user'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
?>
<div class="fs-auth-card">
	<h2 class="fs-auth-card__title">Войти в личный кабинет</h2>

	<?php if ( $login_failed ) : ?>
		<div class="fs-auth-card__error" role="alert">
			Неверный логин или пароль.
		</div>
	<?php endif; ?>

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

		<div class="fs-auth-card__meta">
			<a href="<?php echo esc_url( $lost_pass_url ); ?>">Забыли пароль?</a>
		</div>

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
