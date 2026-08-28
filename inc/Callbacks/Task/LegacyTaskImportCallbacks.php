<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Task;

use Inc\Core\BaseController;
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

	/** Записей за один AJAX-запрос — держит батч в пределах max_execution_time дешёвого хостинга. */
	private const BATCH_SIZE = 15;

	public function __construct(
		private readonly LegacyTaskImportService $importService,
	) {
		parent::__construct();
	}

	/** Общее число записей в файле переноса — для инициализации прогресс-бара. */
	public function ajaxLegacyTaskImportStatus(): void {
		$this->authorize( Nonce::Manager, Capability::ManageLmsPlatform );

		$this->success( array(
			'total'      => $this->importService->totalCount(),
			'batch_size' => self::BATCH_SIZE,
		) );
	}

	/** Импортирует один батч, начиная с переданного offset. */
	public function ajaxLegacyTaskImportBatch(): void {
		$this->authorize( Nonce::Manager, Capability::ManageLmsPlatform );

		$subjectKey = $this->requireKey( 'subject_key', error: 'Не указан предмет.' );
		$offset     = $this->sanitizeInt( 'offset' );

		$authorTaxonomy = $this->sanitizeKey( 'author_taxonomy' ) ?: "{$subjectKey}_author";
		$yearTaxonomy   = $this->sanitizeKey( 'year_taxonomy' ) ?: "{$subjectKey}_year";
		$levelTaxonomy  = $this->sanitizeKey( 'level_taxonomy' ) ?: "{$subjectKey}_level";

		try {
			$report = $this->importService->importBatch(
				$subjectKey,
				$offset,
				self::BATCH_SIZE,
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
