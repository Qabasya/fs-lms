<?php
/**
 * Шаблон страницы авторизации (Sign-In)
 *
 * @package FS LMS
 * @var string $error_message Уведомление после неудачного входа; '' — не показываем
 * @var int    $lock_seconds  Секунды до конца блокировки входа для логина из адреса; 0 — вход открыт
 * @var string $prefill_login Логин из неудачной попытки
 * @var string $consent_url   URL согласия на обработку ПДн; '' — сноску не показываем
 * @var string $logo_url      URL логотипа центра
 * @var string $home_url      URL главной сайта (логотип ведёт на неё)
 * @var string $apply_url     URL страницы подачи заявки на обучение
 */
$locked = $lock_seconds > 0;
?>
<div class="fs-auth-brand">
	<a class="fs-auth-brand__home" href="<?php echo esc_url( $home_url ); ?>">
		<img class="fs-auth-brand__logo" src="<?php echo esc_url( $logo_url ); ?>" alt="Шаг в будущее">
	</a>
	<p class="fs-auth-brand__name">
		Образовательный центр «Шаг в будущее»
		<span class="fs-auth-brand__city">Калининград</span>
	</p>
</div>

<div class="fs-auth-card">
	<h2 class="fs-auth-card__title">Войти в личный кабинет</h2>

	<?php if ( '' !== $error_message ) : ?>
		<div class="fs-auth-card__error" role="alert">
			<?php echo esc_html( $error_message ); ?>
		</div>
	<?php endif; ?>

	<!-- Стандартная форма авторизации WordPress -->
	<form name="loginform" id="loginform" action="<?php echo esc_url( site_url( 'wp-login.php', 'login_post' ) ); ?>" method="post">

		<!-- Маркер: вход инициирован с кастомной страницы (для wp_login_failed) -->
		<input type="hidden" name="fs_lms_login" value="1">

		<div class="fs-auth-card__field-group">
			<span class="dashicons dashicons-email"></span>
			<?php // data-lock-field: поля, которые login-form.js включает, когда блокировка кончилась. ?>
			<input type="text" name="log" id="user_login" placeholder="Email или логин" value="<?php echo esc_attr( $prefill_login ); ?>" required data-lock-field <?php disabled( $locked ); ?>>
		</div>

		<div class="fs-auth-card__field-group fs-lms-secret-field">
			<span class="dashicons dashicons-admin-network"></span>
			<input type="password" name="pwd" id="user_pass" placeholder="Пароль" required data-lock-field <?php disabled( $locked ); ?>>
			<!-- Кнопка показа/скрытия пароля (обработка через JS) -->
			<button type="button" class="js-toggle-secret" aria-label="Показать пароль" data-lock-field <?php disabled( $locked ); ?>>
				<span class="dashicons dashicons-visibility"></span>
			</button>
		</div>

		<?php // Слот невидимой капчи (модуль SmartCaptcha); токен пишет login-form.js. ?>
		<div id="fs-captcha-slot" class="fs-auth-card__captcha" role="region" aria-label="Проверка безопасности"></div>
		<input type="hidden" name="captcha_token" value="">
		<?php // Причина, по которой капча не дошла до браузера (VPN, блокировщик); пишет login-form.js. ?>
		<input type="hidden" name="captcha_unavailable" value="">

		<?php // Кнопка неактивна, пока login-form.js не подключил обработчик: форма стоит выше бандла в футере, и ранний «Войти» уходил без токена и без причины. Во время блокировки она остаётся неактивной до конца паузы. ?>
		<button type="submit" name="wp-submit" id="wp-submit" class="fs-auth-card__submit" data-lock-field disabled>
			Войти
		</button>

		<?php // Пока форма ждёт капчу (до нескольких секунд, если скрипт Яндекса не отвечает), показываем, что она не зависла; переключает login-form.js. ?>
		<div class="fs-apply-card__status" data-login-status role="status" aria-live="polite" hidden>
			<span class="fs-apply-card__spinner" aria-hidden="true"></span>
			<span>Проверяем безопасность соединения…</span>
		</div>

		<!-- Редирект после успешного входа (фильтр для кастомизации) -->
		<input type="hidden" name="redirect_to" value="<?php echo esc_url( apply_filters( 'lms_auth_redirect_url', home_url(), null ) ); ?>">
	</form>

	<?php // Блокировка входа: поля отключены, пока идёт пауза. Таймер и скрытие блока по её концу — login-form.js. Контактов нет намеренно: ученики их знают, а боты не должны получить. ?>
	<?php if ( $locked ) : ?>
		<div class="fs-auth-card__lock" data-login-lock="<?php echo (int) $lock_seconds; ?>" role="status" aria-live="polite">
			<p class="fs-auth-card__lock-timer">Вход откроется через <span data-login-lock-timer></span></p>
			<p class="fs-auth-card__lock-note"><span class="fs-auth-card__lock-line">Для разблокировки подождите заданное время</span> <span class="fs-auth-card__lock-line">или свяжитесь с администратором</span></p>
		</div>
	<?php endif; ?>

	<?php // Сноска — только когда согласие на обработку ПДн заведено: ссылка в никуда хуже её отсутствия. ?>
	<?php if ( '' !== $consent_url ) : ?>
		<div class="fs-auth-card__footer">
			Входя в систему, вы соглашаетесь с <a href="<?php echo esc_url( $consent_url ); ?>">политикой обработки данных</a>.
		</div>
	<?php endif; ?>
</div>

<p class="fs-auth-apply">
	В первый раз? <a href="<?php echo esc_url( $apply_url ); ?>">Подай заявку на обучение</a>
</p>
