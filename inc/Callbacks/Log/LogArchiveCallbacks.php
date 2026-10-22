<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Log;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Services\Log\LogArchiveService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\PluginLogger;

/**
 * Class LogArchiveCallbacks
 *
 * AJAX месячного архива тяжёлых журналов («Журналы» → «Архив»): состояние, скачивание ZIP, очистка.
 * Архив содержит ПД (ФИО участников экзаменов, логины, IP), поэтому скачивание и очистка требуют
 * обоих прав — `ManageLmsPlatform` и `ExportPII` ({@see Authorizer::authorizeAll()}).
 *
 * @package Inc\Callbacks\Log
 */
class LogArchiveCallbacks extends BaseController {

	use Authorizer;

	/**
	 * Права на работу с архивом: доступ к разделу + право выгружать ПД.
	 *
	 * @var Capability[]
	 */
	private const CAPS = array( Capability::ManageLmsPlatform, Capability::ExportPII );

	/**
	 * @param LogArchiveService $archive Месячный архив журналов
	 */
	public function __construct(
		private readonly LogArchiveService $archive,
	) {
		parent::__construct();
	}

	/**
	 * Что попадёт в архив и что сделано с последним. Без params.
	 */
	public function ajaxGetLogArchiveStatus(): void {
		$this->authorizeAll( Nonce::Manager, self::CAPS );

		$this->success( $this->archive->status() );
	}

	/**
	 * Собирает ZIP и возвращает одноразовую ссылку. Без params.
	 */
	public function ajaxExportLogArchive(): void {
		$this->authorizeAll( Nonce::Manager, self::CAPS );

		try {
			$url = $this->archive->export( get_current_user_id() );
		} catch ( \Throwable $e ) {
			PluginLogger::exception( 'LogArchive', $e, array(), true );
			$this->error( 'Не удалось собрать архив: ' . $e->getMessage() );
			return;
		}

		$this->success( array( 'url' => $url ) );
	}

	/**
	 * Удаляет из журналов то, что вошло в последний скачанный архив. Без params.
	 */
	public function ajaxPurgeLogArchive(): void {
		$this->authorizeAll( Nonce::Manager, self::CAPS );

		try {
			$deleted = $this->archive->purge();
		} catch ( \RuntimeException $e ) {
			$this->error( $e->getMessage() );
			return;
		}

		$this->success( array( 'deleted' => $deleted ) );
	}
}
