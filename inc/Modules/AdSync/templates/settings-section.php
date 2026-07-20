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

$ad_secret_set = '' !== $config->hmacSecret();
?>

<form id="fs-adsync-form" class="fs-config-form">
	<div class="fs-card fs-card--flat">

		<div class="fs-card__header">
			<h2 class="fs-card__title">Синхронизация с доменом (AD)</h2>
		</div>

		<div class="fs-card__body">
			<p class="fs-card__desc">
				Python-сервис в локальной сети <strong>сам забирает</strong> задания с этого сайта (pull) — белый IP домен-контроллеру не нужен.
				Эндпоинты: <code>GET /wp-json/fs-lms/v1/ad/jobs</code> и <code>POST /wp-json/fs-lms/v1/ad/ack</code>
				(аутентификация — HMAC по секрету <code>FS_LMS_AD_HMAC_SECRET</code>).
			</p>
			<div class="fs-config-key-row">
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

					<p class="description fs-config-key-row__env-label">Эту HMAC-подпись вставьте в <code>.env</code> файл вашего Python-сервиса:</p>
					<input type="text" class="fs-config-key-output" id="fs-adsync-secret-raw" readonly>
					<button type="button" class="button js-copy-key" data-target="fs-adsync-secret-raw">Скопировать</button>
				</div>
			</div>

			<div class="fs-field">
				<span class="fs-field__label">Направления с доменными учётками</span>
				<?php if ( empty( $subjects ) ) : ?>
					<p class="fs-field__desc">Сначала создайте предметы в разделе «Предметы».</p>
				<?php else : ?>
					<?php $provision_subjects = $config->provisionSubjects(); ?>
					<div class="fs-adsync-subjects">
						<?php foreach ( $subjects as $subject ) : ?>
							<label class="fs-adsync-subjects__item">
								<input
									type="checkbox"
									name="provision_subjects[]"
									value="<?php echo esc_attr( $subject->key ); ?>"
									<?php checked( in_array( $subject->key, $provision_subjects, true ) ); ?>
								/>
								<?php echo esc_html( $subject->name ); ?>
							</label>
						<?php endforeach; ?>
					</div>
					<p class="fs-field__desc">
						Учётные записи в домене создаются только по заявкам отмеченных направлений.
						Ничего не отмечено — учётки не создаются ни для кого.
					</p>
				<?php endif; ?>
			</div>
		</div>

		<div class="fs-card__footer">
			<button type="submit" id="fs-adsync-save" class="button button-primary">
				Сохранить настройки AD
			</button>
			<span class="fs-config-status" id="fs-adsync-status"></span>
		</div>

	</div>
</form>
