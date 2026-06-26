<?php
/**
 * Секция настроек модуля SmartCaptcha в табе «Конфигурация».
 * Рендерится через generic-хук ядра `fs_lms_config_sections` (ядро о модуле не знает).
 *
 * @var \Inc\Modules\SmartCaptcha\Config\SmartCaptchaConfig $config
 *
 * @package Inc\Modules\SmartCaptcha
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

require_once FS_LMS_PATH . 'templates/admin/components/UI/ui_renderers.php';

$site_key    = $config->siteKey();
$server_key  = $config->serverKey();
$site_const  = $config->siteKeyFromConstant();
$srv_const   = $config->serverKeyFromConstant();
?>

<div class="fs-config-section fs-config-section--smart-captcha">
	<h2 class="fs-config-section__title">Настройка Yandex SmartCaptcha</h2>
	<p class="description">
		Защита формы заявки (<code>/lms/apply</code>) от ботов. Оба ключа создаются в консоли
		Yandex Cloud → SmartCaptcha. Можно задать константами <code>FS_LMS_CAPTCHA_SITE_KEY</code> /
		<code>FS_LMS_CAPTCHA_SERVER_KEY</code> в <code>wp-config.php</code> (тогда поля только для чтения).
	</p>

	<form id="fs-smart-captcha-form" class="fs-config-form">
		<div class="fs-config-field">
			<label for="fs-captcha-site" class="fs-config-field__label">
				SmartCaptcha — клиентский ключ
				<?php if ( $site_const ) : ?>
					<?php render_fs_badge( 'wp-config', 'blue' ); ?>
				<?php endif; ?>
				<a href="#" class="fs-config-help-link js-open-help-modal" data-provider="smartcaptcha">
					Как подключить? <span class="dashicons dashicons-external"></span>
				</a>
			</label>
			<input
				type="text"
				id="fs-captcha-site"
				name="captcha_site_key"
				class="regular-text"
				value="<?php echo esc_attr( $site_key ); ?>"
				<?php echo $site_const ? 'disabled readonly' : ''; ?>
			/>
			<p class="description">Публичный ключ виджета Yandex SmartCaptcha на форме <code>/lms/apply</code>.</p>
		</div>

		<div class="fs-config-field">
			<label for="fs-captcha-server" class="fs-config-field__label">
				SmartCaptcha — серверный ключ
				<?php if ( $srv_const ) : ?>
					<?php render_fs_badge( 'wp-config', 'blue' ); ?>
				<?php endif; ?>
			</label>
			<input
				type="text"
				id="fs-captcha-server"
				name="captcha_server_key"
				class="regular-text"
				value="<?php echo esc_attr( $server_key ); ?>"
				<?php echo $srv_const ? 'disabled readonly' : ''; ?>
			/>
			<p class="description">Секретный ключ для серверной проверки токена.</p>
		</div>

		<?php if ( ! $site_const || ! $srv_const ) : ?>
			<div class="fs-config-actions">
				<button type="submit" id="fs-smart-captcha-save" class="button button-primary">Сохранить</button>
				<span class="fs-config-status" id="fs-smart-captcha-status"></span>
			</div>
		<?php endif; ?>
	</form>
</div>
