<?php
/**
 * Секция настроек модуля AdSync в табе «Конфигурация».
 * Рендерится через generic-хук ядра `fs_lms_config_sections` (ядро о модуле не знает).
 *
 * @var \Inc\Modules\AdSync\Config\AdSyncConfig $config
 * @var array                                   $subjects    Список предметов (объекты с ->key/->name).
 * @var array<string, int>                      $counts      Задания очереди по статусам.
 * @var array<string, mixed>                    $state       Состояние доставки (последняя доставка, ошибка, сверка).
 * @var int                                     $pausedUntil Доставка на паузе до (unix), 0 — не на паузе.
 *
 * @package Inc\Modules\AdSync
 */

declare( strict_types=1 );

use Inc\Enums\Ui\Icon;

defined( 'ABSPATH' ) || exit;

require_once FS_LMS_PATH . 'templates/admin/components/UI/ui_renderers.php';

$ad_secret_set = '' !== $config->hmacSecret();
$ad_cert_path  = $config->serverCertPath();
$ad_cert_ok    = $config->hasServerCert();

$ad_last_delivery = ! empty( $state['last_delivery_at'] ) ? wp_date( 'd.m.Y H:i', (int) $state['last_delivery_at'] ) : '—';
$ad_last_error    = (string) ( $state['last_error'] ?? '' );
$ad_reconcile_at  = ! empty( $state['last_reconcile_at'] ) ? wp_date( 'd.m.Y H:i', (int) $state['last_reconcile_at'] ) : '';
?>

<form id="fs-adsync-form" class="fs-config-form">
	<div class="fs-card fs-card--flat">

		<div class="fs-card__header">
			<h2 class="fs-card__title">Синхронизация с доменом (AD)</h2>
		</div>

		<div class="fs-card__body">
			<p class="fs-card__desc">
				Сайт <strong>сам отправляет</strong> задания серверу AdSync в офисе (<code>POST /v1/jobs</code>) и сразу получает результат.
				Если офис недоступен, задания ждут в очереди и уходят при следующей попытке — WP-cron раз в минуту
				(на хостинге надёжнее системный cron на <code>wp-cron.php</code> или <code>wp fs-lms ad flush --respect-pause</code>).
			</p>

			<div class="fs-field">
				<label for="fs-adsync-server-url" class="fs-field__label">Адрес сервера в офисе</label>
				<div class="fs-field__control">
					<input type="text" id="fs-adsync-server-url" name="server_url" inputmode="url"
						placeholder="https://92.101.127.156:8443" value="<?php echo esc_attr( $config->serverUrl() ); ?>">
				</div>
				<p class="fs-field__desc">Белый IP офиса и порт, проброшенный на контейнер AdSync. Только HTTPS.</p>
			</div>

			<div class="fs-config-key-row">
				<div class="fs-config-key-row__header">
					<span class="fs-config-key-row__name">FS_LMS_AD_SERVER_CERT</span>
					<?php
					if ( $ad_cert_ok ) {
						render_fs_badge( 'Задан', 'green' );
					} elseif ( '' !== $ad_cert_path ) {
						render_fs_badge( 'Файл не найден', 'red' );
					} else {
						render_fs_badge( 'Не задан', 'red' );
					}
					?>
				</div>
				<p class="description">
					Путь к сертификату сервера в офисе (<code>server.crt</code>, без ключа). Сайт доверяет только ему.
					Файл кладётся выше <code>public_html</code>, в <code>wp-config.php</code>:
					<code>define( 'FS_LMS_AD_SERVER_CERT', '/путь/к/adsync-server.crt' );</code>
				</p>
			</div>

			<div class="fs-config-key-row">
				<div class="fs-config-key-row__header">
					<span class="fs-config-key-row__name">FS_LMS_AD_HMAC_SECRET</span>
					<?php render_fs_badge( $ad_secret_set ? 'Задан' : 'Не задан', $ad_secret_set ? 'green' : 'red' ); ?>
				</div>
				<p class="description">Секрет подписи запросов сайта к серверу в офисе. В БД не хранится. Сгенерируйте — получите обе строки ниже.</p>
				<div class="fs-config-key-row__actions">
					<button type="button" class="button" data-ad-generate-secret>
						<?php echo $ad_secret_set ? 'Перегенерировать' : 'Сгенерировать'; ?>
					</button>
				</div>
				<div class="fs-config-key-row__output" id="fs-adsync-secret-output" hidden>
					<p class="description">Эту строку вставьте в <code>wp-config.php</code>:</p>
					<textarea class="fs-config-key-output" id="fs-adsync-secret-value" rows="2" readonly></textarea>
					<button type="button" class="button js-copy-key" data-target="fs-adsync-secret-value">Скопировать</button>

					<p class="description fs-config-key-row__env-label">Это значение вставьте в <code>.env</code> сервера AdSync в офисе:</p>
					<input type="text" class="fs-config-key-output" id="fs-adsync-secret-raw" readonly>
					<button type="button" class="button js-copy-key" data-target="fs-adsync-secret-raw">Скопировать</button>
				</div>
			</div>

			<div class="fs-adsync-status" aria-live="polite">
				<dl class="fs-adsync-status__list">
					<dt>Последняя успешная доставка</dt>
					<dd><?php echo esc_html( $ad_last_delivery ); ?></dd>

					<dt>В очереди</dt>
					<dd data-ad-count="pending"><?php echo (int) ( ( $counts['pending'] ?? 0 ) + ( $counts['failed'] ?? 0 ) ); ?></dd>

					<dt>Мёртвые</dt>
					<dd data-ad-count="dead"><?php echo (int) ( $counts['dead'] ?? 0 ); ?></dd>

					<?php if ( $pausedUntil > 0 ) : ?>
						<dt>Доставка</dt>
						<dd class="fs-adsync-status__warn">на паузе до <?php echo esc_html( wp_date( 'H:i', $pausedUntil ) ); ?> — офис недоступен</dd>
					<?php endif; ?>

					<?php if ( '' !== $ad_last_error ) : ?>
						<dt>Последняя ошибка</dt>
						<dd class="fs-adsync-status__warn"><?php echo esc_html( $ad_last_error ); ?></dd>
					<?php endif; ?>
				</dl>

				<div class="fs-adsync-status__actions">
					<button type="button" class="button" data-ad-action="check">Проверить соединение</button>
					<button type="button" class="button" data-ad-action="flush">Отправить сейчас</button>
					<button type="button" class="button" data-ad-action="retry" <?php disabled( 0 === (int) ( $counts['dead'] ?? 0 ) ); ?>>Повторить мёртвые</button>
				</div>
				<p class="fs-adsync-status__result" data-ad-result role="status"></p>
			</div>

			<div class="fs-field">
				<span class="fs-field__label">Направления с доменными учётками</span>
				<?php if ( empty( $subjects ) ) : ?>
					<p class="fs-field__desc">Сначала создайте предметы в разделе «Предметы».</p>
				<?php else : ?>
					<?php
						$provision_subjects = $config->provisionSubjects();
						$selected_count     = count( $provision_subjects );
						$summary_label      = $selected_count > 0
							? sprintf( 'Выбрано направлений: %d', $selected_count )
							: 'Ничего не выбрано';
					?>
					<div class="fs-adsync-subjects" data-fs-dropdown>
						<button type="button" class="fs-adsync-subjects__toggle" aria-expanded="false">
							<span class="fs-adsync-subjects__summary"><?php echo esc_html( $summary_label ); ?></span>
							<?php echo Icon::ChevronDown->svg( 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</button>
						<div class="fs-adsync-subjects__panel" hidden>
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
					</div>
					<p class="fs-field__desc">
						Учётные записи в домене создаются только по заявкам отмеченных направлений.
						Ничего не отмечено — учётки не создаются ни для кого.
					</p>
				<?php endif; ?>
			</div>

			<div class="fs-field">
				<span class="fs-field__label">Сверка: отключать лишние учётки</span>
				<?php render_fs_toggle( 'reconcile_apply', $config->reconcileApply(), array( 'id' => 'fs-adsync-reconcile-apply' ) ); ?>
				<p class="fs-field__desc">
					Раз в сутки сайт отправляет в офис список активных логинов. Выключено — сервер только пишет в журнал,
					кого отключил бы (так стоит поработать первую неделю); включено — отключает учётки в управляемых OU.
					<?php if ( '' !== $ad_reconcile_at ) : ?>
						<br>Последняя сверка: <?php echo esc_html( $ad_reconcile_at . ' — ' . (string) ( $state['last_reconcile_result'] ?? '' ) ); ?>
					<?php endif; ?>
				</p>
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
