<?php

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

require_once FS_LMS_PATH . 'templates/admin/components/UI/ui_renderers.php';

/**
 * @var array $config Payload из PluginConfig::viewState()
 *   ['dadata_token']    => ['value', 'defined_in_config', 'editable']
 *   ['test_env']        => ['value', 'defined_in_config', 'editable']
 *   ['otp_bypass_code'] => ['value', 'defined_in_config', 'editable']
 *   ['enc_key_set']     => bool
 *   ['hash_salt_set']   => bool
 */

$dadata   = $config['dadata_token']    ?? array( 'value' => '', 'defined_in_config' => false, 'editable' => true );
$test_env = $config['test_env']        ?? array( 'value' => false, 'defined_in_config' => false, 'editable' => true );
$otp      = $config['otp_bypass_code'] ?? array( 'value' => '', 'defined_in_config' => false, 'editable' => true );
$enc_set  = (bool) ( $config['enc_key_set']  ?? false );
$salt_set = (bool) ( $config['hash_salt_set'] ?? false );
?>

<div id="tab-config" class="tab-pane active fs-lms-config">

	<div class="header-row">
		<h1 class="wp-heading-inline">Конфигурация плагина</h1>
	</div>

	<?php settings_errors(); ?>

	<!-- ======== Мягкая тройка ======== -->
	<div class="fs-config-section">
		<h2 class="fs-config-section__title">Настройки сервисов</h2>
		<p class="description">
			Значения, заданные через <code>define()</code> в <code>wp-config.php</code>, имеют приоритет и отображаются только для чтения.
		</p>

		<form id="fs-config-form" class="fs-config-form">

			<!-- DaData токен -->
			<div class="fs-config-field">
				<label for="fs-config-dadata-token" class="fs-config-field__label">
					DaData API Token
					<?php if ( $dadata['defined_in_config'] ) : ?>
						<?php render_fs_badge( 'wp-config', 'blue' ); ?>
					<?php endif; ?>
				</label>
				<input
					type="text"
					id="fs-config-dadata-token"
					name="dadata_token"
					class="regular-text"
					value="<?php echo esc_attr( $dadata['value'] ); ?>"
					<?php echo $dadata['editable'] ? '' : 'disabled readonly'; ?>
				/>
				<p class="description">Токен для API DaData (подсказки адресов и ФИО на форме записи).</p>
			</div>

			<!-- OTP bypass code -->
			<div class="fs-config-field">
				<label for="fs-config-otp-bypass" class="fs-config-field__label">
					OTP Bypass Code
					<?php if ( $otp['defined_in_config'] ) : ?>
						<?php render_fs_badge( 'wp-config', 'blue' ); ?>
					<?php endif; ?>
				</label>
				<input
					type="text"
					id="fs-config-otp-bypass"
					name="otp_bypass_code"
					class="regular-text"
					value="<?php echo esc_attr( $otp['value'] ); ?>"
					<?php echo $otp['editable'] ? '' : 'disabled readonly'; ?>
				/>
				<p class="description">Универсальный код для обхода OTP-проверки (для поддержки учеников без доступа к email).</p>
			</div>

			<!-- Тестовое окружение -->
			<div class="fs-config-field fs-config-field--toggle">
				<span class="fs-config-field__label">
					Тестовое окружение (FS_LMS_TEST_ENV)
					<?php if ( $test_env['defined_in_config'] ) : ?>
						<?php render_fs_badge( 'wp-config', 'blue' ); ?>
					<?php endif; ?>
				</span>
				<?php render_fs_toggle( 'test_env', (bool) $test_env['value'], array(
					'id'       => 'fs-config-test-env',
					'readonly' => ! $test_env['editable'],
				) ); ?>
				<p class="description">Отключает капчу, rate-limit и отправку email. Включайте только на dev-стенде.</p>
			</div>

			<?php if ( $dadata['editable'] || $otp['editable'] || $test_env['editable'] ) : ?>
				<div class="fs-config-actions">
					<button type="submit" id="fs-config-save" class="button button-primary">
						Сохранить настройки
					</button>
					<span class="fs-config-status" id="fs-config-status"></span>
				</div>
			<?php endif; ?>

		</form>
	</div>

	<!-- ======== Ключи шифрования ======== -->
	<div class="fs-config-section fs-config-section--keys">
		<h2 class="fs-config-section__title">Ключи шифрования</h2>
		<p class="description">
			Ключи <strong>никогда не хранятся в базе данных</strong>.
			Скопируйте сгенерированную строку и добавьте её в <code>wp-config.php</code>.
		</p>

		<!-- ENC KEY -->
		<div class="fs-config-key-row">
			<div class="fs-config-key-row__header">
				<span class="fs-config-key-row__name">FS_LMS_ENC_KEY</span>
				<?php render_fs_badge( $enc_set ? 'Задан' : 'Не задан', $enc_set ? 'green' : 'red' ); ?>
			</div>
			<p class="description">Ключ симметричного шифрования PII-данных (libsodium). Смена ключа делает все зашифрованные данные нечитаемыми.</p>
			<div class="fs-config-key-row__actions">
				<button type="button" class="button js-generate-key" data-type="enc_key">
					<?php echo $enc_set ? 'Перегенерировать' : 'Сгенерировать'; ?>
				</button>
			</div>
			<div class="fs-config-key-row__output" id="fs-enc-key-output" hidden>
				<textarea class="fs-config-key-output" id="fs-enc-key-value" rows="3" readonly></textarea>
				<button type="button" class="button js-copy-key" data-target="fs-enc-key-value">Скопировать</button>
			</div>
		</div>

		<!-- HASH SALT -->
		<div class="fs-config-key-row">
			<div class="fs-config-key-row__header">
				<span class="fs-config-key-row__name">FS_LMS_HASH_SALT</span>
				<?php render_fs_badge( $salt_set ? 'Задан' : 'Не задан', $salt_set ? 'green' : 'red' ); ?>
			</div>
			<p class="description">Соль для хеширования IP-адресов и OTP-кодов.</p>
			<div class="fs-config-key-row__actions">
				<button type="button" class="button js-generate-key" data-type="hash_salt">
					<?php echo $salt_set ? 'Перегенерировать' : 'Сгенерировать'; ?>
				</button>
			</div>
			<div class="fs-config-key-row__output" id="fs-hash-salt-output" hidden>
				<textarea class="fs-config-key-output" id="fs-hash-salt-value" rows="3" readonly></textarea>
				<button type="button" class="button js-copy-key" data-target="fs-hash-salt-value">Скопировать</button>
			</div>
		</div>

	</div>

</div>
