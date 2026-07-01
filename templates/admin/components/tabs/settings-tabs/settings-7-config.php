<?php

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

require_once FS_LMS_PATH . 'templates/admin/components/UI/ui_renderers.php';

/**
 * @var array $config Payload из PluginConfig::viewState()
 *   ['test_env']        => ['value', 'defined_in_config', 'editable']
 *   ['otp_bypass_code'] => ['value', 'defined_in_config', 'editable']
 *   ['enc_key_set']     => bool
 *   ['hash_salt_set']   => bool
 */

$test_env = $config['test_env']        ?? array( 'value' => false, 'defined_in_config' => false, 'editable' => true );
$otp      = $config['otp_bypass_code'] ?? array( 'value' => '', 'defined_in_config' => false, 'editable' => true );
$enc_set  = (bool) ( $config['enc_key_set']   ?? false );
$salt_set = (bool) ( $config['hash_salt_set'] ?? false );
?>

<div id="tab-config" class="tab-pane active">
<div class="fs-lms-config">

	<div class="fs-page-header">
		<div class="fs-page-header__content">
			<h1 class="fs-page-header__title">Конфигурация плагина</h1>
		</div>
	</div>

	<?php settings_errors(); ?>

	<!-- ======== Тестовое окружение ======== -->
	<form id="fs-config-form" class="fs-config-form">
		<div class="fs-card fs-card--flat">
			<div class="fs-card__header">
				<h2 class="fs-card__title">Тестовое окружение</h2>
			</div>
			<div class="fs-card__body">
				<p class="fs-card__desc">
					Настройки для разработки и поддержки. Значения, заданные через <code>define()</code> в <code>wp-config.php</code>, имеют приоритет и отображаются только для чтения.
				</p>

				<div class="fs-field">
					<span class="fs-field__label">
						Тестовое окружение (FS_LMS_TEST_ENV)
						<?php if ( $test_env['defined_in_config'] ) : ?>
							<?php render_fs_badge( 'wp-config', 'blue' ); ?>
						<?php endif; ?>
					</span>
					<?php render_fs_toggle( 'test_env', (bool) $test_env['value'], array(
						'id'       => 'fs-config-test-env',
						'readonly' => ! $test_env['editable'],
					) ); ?>
					<p class="fs-field__desc">Отключает капчу, rate-limit и отправку email. Включайте только на dev-стенде.</p>
				</div>

				<div class="fs-field">
					<label for="fs-config-otp-bypass" class="fs-field__label">
						OTP Bypass Code
						<?php if ( $otp['defined_in_config'] ) : ?>
							<?php render_fs_badge( 'wp-config', 'blue' ); ?>
						<?php endif; ?>
					</label>
					<div class="fs-field__control">
						<input
							type="text"
							id="fs-config-otp-bypass"
							name="otp_bypass_code"
							class="regular-text"
							value="<?php echo esc_attr( $otp['value'] ); ?>"
							<?php echo $otp['editable'] ? '' : 'disabled readonly'; ?>
						/>
					</div>
					<p class="fs-field__desc">Универсальный код для обхода OTP-проверки (для поддержки учеников без доступа к email).</p>
				</div>

			</div>
			<?php if ( $otp['editable'] || $test_env['editable'] ) : ?>
				<div class="fs-card__footer">
					<button type="submit" id="fs-config-save" class="button button-primary">
						Сохранить настройки
					</button>
					<span class="fs-config-status" id="fs-config-status"></span>
				</div>
			<?php endif; ?>
		</div>
	</form>

	<!-- ======== Ключи шифрования ======== -->
	<div class="fs-card fs-card--flat">
		<div class="fs-card__header">
			<h2 class="fs-card__title">Ключи шифрования</h2>
		</div>
		<div class="fs-card__body">
			<p class="fs-card__desc">
				Ключи <strong>никогда не хранятся в базе данных</strong>.
				Скопируйте сгенерированную строку и добавьте её в <code>wp-config.php</code>.
			</p>

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

	<!-- ======== Настройка заявок ======== -->
	<form id="fs-applications-form" class="fs-config-form">
		<div class="fs-card fs-card--flat">
			<div class="fs-card__header">
				<h2 class="fs-card__title">Настройка заявок</h2>
			</div>
			<div class="fs-card__body">
				<p class="fs-card__desc">
					Привязка заявки к направлению: ученик вводит код на форме записи, заявка привязывается к предмету
					(он предвыбирается при зачислении).
				</p>

				<div class="fs-field">
					<span class="fs-field__label">Привязать заявку к направлению</span>
					<?php render_fs_toggle( 'applications_bind_to_subject', (bool) ( $config['applications_bind_to_subject'] ?? false ), array(
						'id' => 'fs-config-bind-subject',
					) ); ?>
					<p class="fs-field__desc">При включении форма <code>/lms/apply</code> требует ввести код направления.</p>
				</div>

				<div class="fs-field fs-direction-codes" id="fs-direction-codes">
					<span class="fs-field__label">Коды направлений</span>
					<?php if ( empty( $subjects ) ) : ?>
						<p class="fs-field__desc">Сначала создайте предметы в разделе «Предметы».</p>
					<?php else : ?>
						<?php $direction_codes = (array) ( $config['direction_codes'] ?? array() ); ?>
						<div class="fs-direction-codes__rows">
							<?php foreach ( $subjects as $subject ) : ?>
								<div class="fs-direction-codes__row">
									<label class="fs-direction-codes__name" for="fs-dir-<?php echo esc_attr( $subject->key ); ?>">
										<?php echo esc_html( $subject->name ); ?>
									</label>
									<input
										type="text"
										id="fs-dir-<?php echo esc_attr( $subject->key ); ?>"
										class="regular-text fs-direction-codes__input"
										data-direction-code
										data-subject="<?php echo esc_attr( $subject->key ); ?>"
										value="<?php echo esc_attr( (string) ( $direction_codes[ $subject->key ] ?? '' ) ); ?>"
										placeholder="напр. 111"
									/>
								</div>
							<?php endforeach; ?>
						</div>
						<p class="fs-field__desc">Код, который ученик вводит на форме записи для привязки к направлению.</p>
					<?php endif; ?>
				</div>

			</div>
			<div class="fs-card__footer">
				<button type="submit" id="fs-applications-save" class="button button-primary">
					Сохранить настройки заявок
				</button>
				<span class="fs-config-status" id="fs-applications-status"></span>
			</div>
		</div>
	</form>

	<?php
	/**
	 * Generic-хук: опциональные модули дорисовывают свои секции конфигурации.
	 * Без подписчиков — no-op. Ядро о модулях не знает.
	 *
	 * @param array $subjects Список предметов.
	 */
	do_action( 'fs_lms_config_sections', $subjects );
	?>

</div>
</div>

<?php require_once FS_LMS_PATH . 'templates/admin/components/modals/help-modal.php'; ?>
