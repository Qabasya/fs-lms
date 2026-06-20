<?php
/**
 * Секция настроек модуля AdSync в табе «Конфигурация».
 * Рендерится через generic-хук ядра `fs_lms_config_sections` (ядро о модуле не знает).
 *
 * @var \Inc\Modules\AdSync\Config\AdSyncConfig $config
 * @var array                                   $subjects Список предметов (объекты с ->key/->name).
 *
 * @package Inc\Modules\AdSync
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

require_once FS_LMS_PATH . 'templates/admin/components/UI/ui_renderers.php';

$ad_data         = $config->get();
$ad_enabled      = (bool) ( $ad_data['enabled'] ?? false );
$ad_const        = defined( 'FS_LMS_AD_SYNC' );
$ad_effective    = $config->isEnabled();
$ad_secret_set   = '' !== $config->hmacSecret();
?>

<div class="fs-config-section fs-config-section--adsync">
	<h2 class="fs-config-section__title">Синхронизация с доменом (AD)</h2>
	<p class="description">
		Создание учётных записей в Active Directory по заявкам. Python-сервис в локальной сети
		<strong>сам забирает</strong> задания с этого сайта (pull) — белый IP домен-контроллеру не нужен.
		Эндпоинты: <code>GET /wp-json/fs-lms/v1/ad/jobs</code> и <code>POST /wp-json/fs-lms/v1/ad/ack</code>
		(аутентификация — HMAC по секрету <code>FS_LMS_AD_HMAC_SECRET</code>).
		Отключается тумблером ниже или константой <code>FS_LMS_AD_SYNC</code> в <code>wp-config.php</code>.
	</p>

	<form id="fs-adsync-form" class="fs-config-form">

		<div class="fs-config-field fs-config-field--toggle">
			<span class="fs-config-field__label">
				Включить синхронизацию с AD
				<?php if ( $ad_const ) : ?>
					<?php render_fs_badge( 'wp-config', 'blue' ); ?>
				<?php endif; ?>
			</span>
			<?php
			// Зависимость: AD требует «Привязки заявки к направлению» (иначе нет subject_key для выбора группы).
			$ad_toggle_value = $bind_to_subject ? ( $ad_const ? $ad_effective : $ad_enabled ) : false;
			render_fs_toggle( 'ad_sync_enabled', $ad_toggle_value, array(
				'id'       => 'fs-adsync-enabled',
				'readonly' => $ad_const || ! $bind_to_subject,
			) );
			?>
			<?php if ( ! $bind_to_subject ) : ?>
				<p class="description fs-adsync-dep-warning">
					⚠ Сначала включите «Привязать заявку к направлению» в разделе «Настройка заявок» выше —
					без направления у заявки нет <code>subject_key</code>, и Python не сможет выбрать AD-группу.
				</p>
			<?php else : ?>
				<p class="description">Рантайм-провижн/деправижн. При выключении — нулевой след (ноль хуков). Чистка брошенных/удалённых — через сверку (reconcile).</p>
			<?php endif; ?>
		</div>

		<div class="fs-config-field fs-config-key-row">
			<div class="fs-config-key-row__header">
				<span class="fs-config-key-row__name">FS_LMS_AD_HMAC_SECRET</span>
				<?php render_fs_badge( $ad_secret_set ? 'Задан' : 'Не задан', $ad_secret_set ? 'green' : 'red' ); ?>
			</div>
			<p class="description">Секрет для HMAC-подписи запросов Python ↔ WP. В БД не хранится. Сгенерируйте — получите обе строки ниже.</p>
			<div class="fs-config-key-row__actions">
				<button type="button" class="button" data-ad-generate-secret>
					<?php echo $ad_secret_set ? 'Перегенерировать' : 'Сгенерировать'; ?>
				</button>
			</div>
			<div class="fs-config-key-row__output" id="fs-adsync-secret-output" hidden>
				<p class="description">Эту строку вставьте в <code>wp-config.php</code>:</p>
				<textarea class="fs-config-key-output" id="fs-adsync-secret-value" rows="2" readonly></textarea>
				<button type="button" class="button js-copy-key" data-target="fs-adsync-secret-value">Скопировать</button>

				<p class="description fs-adsync-env-label">Эту HMAC-подпись вставьте в <code>.env</code> файл вашего Python-сервиса:</p>
				<input type="text" class="fs-config-key-output" id="fs-adsync-secret-raw" readonly>
				<button type="button" class="button js-copy-key" data-target="fs-adsync-secret-raw">Скопировать</button>
			</div>
		</div>

		<div class="fs-config-actions">
			<button type="submit" id="fs-adsync-save" class="button button-primary">Сохранить настройки AD</button>
			<span class="fs-config-status" id="fs-adsync-status"></span>
		</div>

	</form>
</div>
