<?php

declare( strict_types=1 );

namespace Inc\Modules\AdSync\Callbacks;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Modules\AdSync\Config\AdSyncConfig;
use Inc\Modules\AdSync\Repositories\AdOutboxRepository;
use Inc\Modules\AdSync\Services\AdDeliveryService;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class AdSyncSettingsCallbacks
 *
 * AJAX-обработчики настроек модуля AdSync.
 * Включение/выключение модуля перенесено на Dashboard (fs_lms_module_toggle_ad_sync).
 * HMAC-секрет генерируется на клиенте и не хранится в БД. Сохраняемые поля:
 * `provision_subjects` (направления с доменными учётками), `server_url` (адрес
 * сервера в офисе), `reconcile_apply` (сверка отключает лишние учётки).
 * Плюс действия над доставкой: проверка соединения, отправка сейчас, «мёртвые» — в очередь.
 *
 * @package Inc\Modules\AdSync\Callbacks
 */
class AdSyncSettingsCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly AdSyncConfig       $config,
		private readonly SubjectRepository  $subjects,
		private readonly AdDeliveryService  $delivery,
		private readonly AdOutboxRepository $outbox,
	) {
		parent::__construct();
	}

	public function ajaxSaveSettings(): void {
		$this->authorize( Nonce::Config, Capability::Admin );

		$raw = $this->unslashArray( 'provision_subjects' );

		// Валидация по readAll() (не readActive): уже сохранённый архивный предмет не должен выпадать молча.
		$validKeys = array_map( static fn( $s ) => $s->key, $this->subjects->readAll() );
		$keys      = array();
		foreach ( $raw as $key ) {
			$key = $this->sanitizeKeyValue( $key );
			if ( '' !== $key && in_array( $key, $validKeys, true ) ) {
				$keys[] = $key;
			}
		}

		$serverUrl = trim( $this->sanitizeText( 'server_url' ) );
		if ( '' !== $serverUrl && ! $this->isServerUrl( $serverUrl ) ) {
			$this->error( 'Адрес сервера — вида https://92.101.127.156:8443, только HTTPS.' );
			return;
		}

		$this->config->save( array(
			'provision_subjects' => array_values( array_unique( $keys ) ),
			'server_url'         => rtrim( $serverUrl, '/' ),
			'reconcile_apply'    => $this->sanitizeBool( 'reconcile_apply' ),
		) );

		$this->success( array( 'message' => 'Настройки сохранены.' ) );
	}

	/** Проверка соединения с сервером в офисе. */
	public function ajaxCheckConnection(): void {
		$this->authorize( Nonce::Config, Capability::Admin );

		$result = $this->delivery->checkConnection();
		$result['ok'] ? $this->success( $result ) : $this->error( $result['message'] );
	}

	/** Отправить очередь сейчас, не дожидаясь cron (и паузы простоя). */
	public function ajaxDeliverNow(): void {
		$this->authorize( Nonce::Config, Capability::Admin );

		$report  = $this->delivery->deliverPending( 50, true );
		$message = sprintf( 'Отправлено: %d, ошибок: %d, без данных: %d.', $report['sent'], $report['failed'], $report['dead'] )
			. ( '' !== $report['skipped'] ? ' ' . $report['skipped'] : '' );

		$this->success( array( 'message' => $message, 'counts' => $this->outbox->countByStatus() ) );
	}

	/** «Мёртвые» задания — снова в очередь. */
	public function ajaxRetryDead(): void {
		$this->authorize( Nonce::Config, Capability::Admin );

		$count = $this->outbox->retryDead();
		$this->success( array(
			'message' => sprintf( 'Возвращено в очередь: %d.', $count ),
			'counts'  => $this->outbox->countByStatus(),
		) );
	}

	/** https://хост[:порт][/путь] — сайт ходит в офис только по HTTPS. */
	private function isServerUrl( string $url ): bool {
		$parts = wp_parse_url( $url );

		return is_array( $parts )
			&& 'https' === ( $parts['scheme'] ?? '' )
			&& '' !== ( $parts['host'] ?? '' )
			&& ! isset( $parts['query'] )
			&& ! isset( $parts['user'] );
	}
}
