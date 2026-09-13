<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Task;

use Inc\Core\BaseController;
use Inc\DTO\Task\LegacyTaskRowDTO;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Services\Task\LegacyTaskImportService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class LegacyTaskImportCallbacks
 *
 * AJAX-обработчики разового переноса заданий со старой версии сайта.
 * Только транспорт — бизнес-логика в {@see LegacyTaskImportService}.
 *
 * @package Inc\Callbacks\Task
 */
class LegacyTaskImportCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly LegacyTaskImportService $importService,
	) {
		parent::__construct();
	}

	/**
	 * Импортирует один батч записей из файла, выбранного на странице переноса.
	 *
	 * Файл разбирает браузер: `rows` — JSON-массив записей текущего батча,
	 * `offset` — позиция первой из них в файле (для нумерации строк в отчёте).
	 */
	public function ajaxLegacyTaskImportBatch(): void {
		$this->authorize( Nonce::Manager, Capability::ManageLmsPlatform );

		$subjectKey = $this->requireKey( 'subject_key', error: 'Не указан предмет.' );
		$offset     = $this->sanitizeInt( 'offset' );

		$rows = json_decode( $this->unslashRawString( 'rows' ), true );
		if ( ! is_array( $rows ) || array() === $rows || ! array_is_list( $rows ) ) {
			$this->error( 'Батч переноса пуст или повреждён.' );
		}

		if ( count( $rows ) > LegacyTaskImportService::BATCH_SIZE ) {
			$this->error( 'В батче больше ' . LegacyTaskImportService::BATCH_SIZE . ' записей.' );
		}

		$authorTaxonomy = $this->sanitizeKey( 'author_taxonomy' ) ?: "{$subjectKey}_author";
		$yearTaxonomy   = $this->sanitizeKey( 'year_taxonomy' ) ?: "{$subjectKey}_year";
		$levelTaxonomy  = $this->sanitizeKey( 'level_taxonomy' ) ?: "{$subjectKey}_level";

		try {
			$report = $this->importService->importBatch(
				$subjectKey,
				$offset,
				array_map( array( LegacyTaskRowDTO::class, 'fromArray' ), $rows ),
				$authorTaxonomy,
				$yearTaxonomy,
				$levelTaxonomy
			);
		} catch ( \Throwable $e ) {
			$this->error( $e->getMessage() );
			return;
		}

		$this->success( $report );
	}
}
