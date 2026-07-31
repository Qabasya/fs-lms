<?php

declare( strict_types=1 );

namespace Inc\Services\Import;

use Inc\Contracts\RowImporterInterface;
use Inc\Enums\Import\ImportMode;

/**
 * Class RowImporterRegistry
 *
 * Реестр импортёров строки CSV: режим импорта → реализация.
 *
 * @package Inc\Services\Import
 *
 * Вызывающий код (коллбэк AJAX) зависит только от {@see RowImporterInterface};
 * какой класс обслуживает режим — знает реестр. Новый режим = кейс в
 * {@see ImportMode} + строка здесь.
 */
readonly class RowImporterRegistry {

	/**
	 * @param StudentRowImporter         $archive  Архивные записи (без WP-учёток)
	 * @param EnrolledStudentRowImporter $enrolled Полное зачисление (с учётками)
	 */
	public function __construct(
		private StudentRowImporter         $archive,
		private EnrolledStudentRowImporter $enrolled,
	) {}

	/**
	 * Импортёр строки для режима.
	 *
	 * @param ImportMode $mode Режим импорта
	 */
	public function for( ImportMode $mode ): RowImporterInterface {
		return match ( $mode ) {
			ImportMode::Enrolled => $this->enrolled,
			ImportMode::Archive  => $this->archive,
		};
	}
}
