<?php

declare( strict_types=1 );

namespace Inc\Services\Subject\Bundle;

use Inc\DTO\Subject\BundleOptionsDTO;
use Inc\Services\Subject\Import\ImportedEntitiesCollector;
use Inc\DTO\Subject\SubjectImportReportDTO;
use Inc\Services\Export\OneTimeDownloadService;
use Inc\Services\Subject\Import\ImportRollbackService;
use Inc\Shared\PluginLogger;
use RuntimeException;

/**
 * Class SubjectBundlePackager
 *
 * Оркестратор пакета переноса: файл ↔ содержимое.
 *
 * @package Inc\Services\Subject\Bundle
 *
 * ### Зачем отдельный слой
 *
 * {@see SubjectBundleExportService} и {@see SubjectBundleImportService} знают
 * только про содержимое пакета — манифест, записи, ссылки. Всё, что связано с
 * файлом (создать ZIP, распаковать во временный каталог, проверить контрольные
 * суммы, отдать одноразовую ссылку, прибрать за собой), собрано здесь. Иначе
 * оба сервиса пришлось бы учить работе с диском, а WP-CLI и AJAX — повторять
 * одну и ту же последовательность шагов.
 *
 * ### Временные каталоги
 *
 * Распаковка идёт в уникальный подкаталог `uploads/lms-bundle-tmp/`, который
 * удаляется в `finally` — и при успехе, и при ошибке. Оставлять распакованный
 * пакет с содержимым предмета в uploads нельзя.
 */
class SubjectBundlePackager {

	/**
	 * Подкаталог uploads для временной распаковки.
	 */
	private const string TMP_SUBDIR = '/lms-bundle-tmp/';

	/**
	 * Конструктор.
	 *
	 * @param SubjectBundleExportService $exporter  Сбор содержимого пакета
	 * @param SubjectBundleImportService $importer  Восстановление содержимого
	 * @param StudentBundleExporter      $students  Сбор раздела учеников
	 * @param StudentBundleImporter      $enroller  Восстановление раздела учеников
	 * @param BundleArchive              $archive   Чтение/запись ZIP
	 * @param OneTimeDownloadService     $downloads Одноразовые ссылки
	 * @param ImportRollbackService      $rollback  Компенсирующее удаление
	 */
	public function __construct(
		private readonly SubjectBundleExportService $exporter,
		private readonly SubjectBundleImportService $importer,
		private readonly StudentBundleExporter      $students,
		private readonly StudentBundleImporter      $enroller,
		private readonly BundleArchive              $archive,
		private readonly OneTimeDownloadService     $downloads,
		private readonly ImportRollbackService      $rollback,
	) {}

	/**
	 * Собирает ZIP-пакет предмета и возвращает данные для UI.
	 *
	 * @param string           $subjectKey Ключ предмета
	 * @param BundleOptionsDTO $options    Объём пакета
	 *
	 * @return array{url: string, filename: string, counts: array<string, int>, warnings: string[], size: int}
	 *
	 * @throws RuntimeException При ошибке упаковки
	 */
	public function pack( string $subjectKey, BundleOptionsDTO $options ): array {
		$built = $this->exporter->build( $subjectKey, $options );

		if ( $options->includeStudents ) {
			$students                       = $this->students->collect( $subjectKey, $built['manifest'] );
			$built['manifest']['students']  = $students['data'];
			$built['counts']['students']    = $students['count'];
			$built['warnings']              = array_merge( $built['warnings'], $students['warnings'] );
		}

		$filename = sprintf( 'subject-%s-%s.%s', $subjectKey, wp_date( 'Y-m-d' ), BundleSchema::EXTENSION );
		$dir      = $this->makeTempDir();

		try {
			$tmpPath = $dir . DIRECTORY_SEPARATOR . sanitize_file_name( $filename );

			$this->archive->write( $built['manifest'], $built['files'], $tmpPath );

			$size = (int) ( filesize( $tmpPath ) ?: 0 );

			return array(
				// forFile() переносит архив в каталог экспортов — временный каталог
				// остаётся пустым и удаляется в finally.
				'url'      => $this->downloads->forFile( $tmpPath, $filename, BundleSchema::MIME ),
				'filename' => $filename,
				'counts'   => $built['counts'],
				'warnings' => $built['warnings'],
				'size'     => $size,
			);
		} finally {
			$this->removeDir( $dir );
		}
	}

	/**
	 * Предпросмотр импорта: распаковывает, проверяет и считает — без записи в БД.
	 *
	 * @param string $archivePath Путь к загруженному ZIP
	 *
	 * @return SubjectImportReportDTO
	 *
	 * @throws RuntimeException При небезопасном или повреждённом архиве
	 */
	public function preview( string $archivePath ): SubjectImportReportDTO {
		$dir = $this->makeTempDir();

		try {
			$manifest = $this->archive->read( $archivePath, $dir );

			// Целостность проверяется до подсчётов: показывать «будет создано 500
			// записей» по битому архиву — вводить администратора в заблуждение.
			$this->archive->verifyChecksums( (array) ( $manifest['media'] ?? array() ), $dir );

			return $this->importer->preview( $manifest );
		} finally {
			$this->removeDir( $dir );
		}
	}

	/**
	 * Импортирует пакет целиком.
	 *
	 * @param string $archivePath Путь к загруженному ZIP
	 *
	 * @return SubjectImportReportDTO
	 *
	 * @throws RuntimeException При ошибке импорта (созданное уже откачено)
	 */
	public function unpack( string $archivePath ): SubjectImportReportDTO {
		$dir     = $this->makeTempDir();
		$created = new ImportedEntitiesCollector();
		$mapper  = new ExportIdMapper();

		try {
			$manifest = $this->archive->read( $archivePath, $dir );

			// Проверка контрольных сумм — ДО первой записи в БД.
			$this->archive->verifyChecksums( (array) ( $manifest['media'] ?? array() ), $dir );

			$report = $this->importer->import( $manifest, $dir, $created, $mapper );

			// Ученики восстанавливаются последними: их группы ссылаются на курс,
			// а карта курсов заполняется только после импорта контента.
			if ( isset( $manifest['students'] ) ) {
				$enrolled = $this->enroller->restore( (array) $manifest['students'], $report->subjectKey, $mapper, $created );

				$report = new SubjectImportReportDTO(
					dryRun:      false,
					subjectKey:  $report->subjectKey,
					subjectName: $report->subjectName,
					counts:      array_merge( $report->counts, array( 'students' => $enrolled['count'] ) ),
					warnings:    array_merge( $report->warnings, $enrolled['warnings'] ),
				);
			}

			return $report;
		} catch ( \Throwable $e ) {
			// Контентную часть откатывает сам импортёр; здесь добираем то, что
			// могло быть создано на шаге учеников.
			$this->rollback->undo( $created );
			throw $e;
		} finally {
			$this->removeDir( $dir );
		}
	}

	/**
	 * Создаёт уникальный временный каталог.
	 *
	 * @return string Абсолютный путь без завершающего слеша
	 *
	 * @throws RuntimeException Если каталог не удалось создать
	 */
	private function makeTempDir(): string {
		$uploadDir = wp_upload_dir();
		$dir       = rtrim( $uploadDir['basedir'] . self::TMP_SUBDIR . wp_generate_password( 12, false ), '/\\' );

		if ( ! wp_mkdir_p( $dir ) ) {
			throw new RuntimeException( 'Не удалось создать временный каталог для пакета.' );
		}

		return $dir;
	}

	/**
	 * Рекурсивно удаляет временный каталог.
	 *
	 * @param string $dir Путь к каталогу
	 *
	 * @return void
	 */
	private function removeDir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		try {
			$items = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::CHILD_FIRST
			);

			foreach ( $items as $item ) {
				if ( $item->isDir() ) {
					rmdir( $item->getPathname() );
				} else {
					wp_delete_file( $item->getPathname() );
				}
			}

			rmdir( $dir );
		} catch ( \Throwable $e ) {
			// Мусор во временном каталоге не повод валить импорт — только лог.
			PluginLogger::exception( 'SUBJECT_BUNDLE', $e, array( 'tmp_dir' => $dir ), true );
		}
	}
}
