<?php

declare( strict_types=1 );

namespace Inc\Cli;

use Inc\Contracts\ServiceInterface;
use Inc\DTO\Subject\BundleOptionsDTO;
use Inc\Services\Subject\Bundle\SubjectBundlePackager;
use WP_CLI;

/**
 * Class SubjectBundleCommand
 *
 * WP-CLI команды переноса предмета (Этап 7).
 *
 * @package Inc\Cli
 *
 * ### Зачем CLI, если есть UI
 *
 * Пакет крупного предмета с медиа легко перерастает `upload_max_filesize`
 * и `max_execution_time` хостинга, а чанкованная загрузка через админку
 * решает только половину проблемы (лимит времени остаётся). CLI снимает оба
 * ограничения и работает с файлом на диске, не гоняя его через HTTP.
 *
 * ### Использование
 *
 * ```
 * wp fs-lms subject export math --out=math.zip [--no-curriculum] [--no-media] [--students]
 * wp fs-lms subject import math.zip [--dry-run]
 * ```
 *
 * В Docker-окружении проекта:
 * `docker compose run --rm wpcli wp fs-lms subject export math --out=/tmp/math.zip`
 */
class SubjectBundleCommand implements ServiceInterface {

	/**
	 * Конструктор.
	 *
	 * @param SubjectBundlePackager $packager Оркестратор пакета
	 */
	public function __construct(
		private readonly SubjectBundlePackager $packager,
	) {}

	/**
	 * Регистрирует команду, если плагин запущен под WP-CLI.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		WP_CLI::add_command( 'fs-lms subject export', array( $this, 'export' ) );
		WP_CLI::add_command( 'fs-lms subject import', array( $this, 'import' ) );
	}

	/**
	 * Собирает пакет предмета в файл.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : Ключ предмета (например, math).
	 *
	 * [--out=<file>]
	 * : Путь к создаваемому ZIP. По умолчанию — subject-<key>-<дата>.zip в текущем каталоге.
	 *
	 * [--no-curriculum]
	 * : Не включать работы, контрольные, уроки и курсы — только банк заданий и статей.
	 *
	 * [--no-media]
	 * : Не включать медиафайлы.
	 *
	 * [--students]
	 * : Включить учеников, их учётки и группы. Внимание: файл будет содержать персональные данные.
	 *
	 * @param string[]              $args       Позиционные аргументы
	 * @param array<string, string> $assocArgs  Именованные аргументы
	 *
	 * @return void
	 */
	public function export( array $args, array $assocArgs ): void {
		$key = (string) ( $args[0] ?? '' );

		if ( '' === $key ) {
			WP_CLI::error( 'Укажите ключ предмета: wp fs-lms subject export <key>' );
		}

		$options = new BundleOptionsDTO(
			includeCurriculum: ! isset( $assocArgs['no-curriculum'] ),
			includeMedia:      ! isset( $assocArgs['no-media'] ),
			includeStudents:   isset( $assocArgs['students'] ),
		);

		try {
			$result = $this->packager->pack( $key, $options );
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}

		$target = (string) ( $assocArgs['out'] ?? $result['filename'] );

		// pack() отдаёт одноразовую ссылку — в CLI она бесполезна, поэтому
		// забираем файл из каталога экспортов на диск по указанному пути.
		$this->moveToTarget( $result['url'], $target );

		foreach ( $result['warnings'] as $warning ) {
			WP_CLI::warning( $warning );
		}

		WP_CLI::success( sprintf(
			'Пакет собран: %s (%s). Состав: %s',
			$target,
			size_format( (int) $result['size'] ),
			$this->formatCounts( $result['counts'] )
		) );
	}

	/**
	 * Импортирует пакет предмета из файла.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Путь к ZIP-пакету.
	 *
	 * [--dry-run]
	 * : Только показать, что будет создано, без записи в БД.
	 *
	 * @param string[]              $args      Позиционные аргументы
	 * @param array<string, string> $assocArgs Именованные аргументы
	 *
	 * @return void
	 */
	public function import( array $args, array $assocArgs ): void {
		$file = (string) ( $args[0] ?? '' );

		if ( '' === $file || ! is_readable( $file ) ) {
			WP_CLI::error( "Файл пакета «{$file}» не найден или недоступен для чтения." );
		}

		$dryRun = isset( $assocArgs['dry-run'] );

		try {
			$report = $dryRun
				? $this->packager->preview( $file )
				: $this->packager->unpack( $file );
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}

		foreach ( $report->warnings as $warning ) {
			WP_CLI::warning( $warning );
		}

		if ( ! $report->isImportable() ) {
			foreach ( $report->collisions as $collision ) {
				WP_CLI::warning( $collision );
			}
			WP_CLI::error( 'Импорт невозможен — устраните конфликты и повторите.' );
			return;
		}

		WP_CLI::success( sprintf(
			'%s предмет «%s». Состав: %s',
			$dryRun ? 'Проверен' : 'Импортирован',
			$report->subjectName,
			$this->formatCounts( $report->counts )
		) );
	}

	/**
	 * Переносит собранный пакет из каталога экспортов по указанному пути.
	 *
	 * @param string $url    Одноразовая ссылка, отданная упаковщиком
	 * @param string $target Целевой путь на диске
	 *
	 * @return void
	 */
	private function moveToTarget( string $url, string $target ): void {
		$token = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$meta  = get_transient( 'fs_lms_export_' . $token );

		if ( ! is_array( $meta ) || empty( $meta['file'] ) || ! is_readable( (string) $meta['file'] ) ) {
			WP_CLI::error( 'Пакет собран, но временный файл не найден — проверьте права на каталог uploads.' );
			return;
		}

		delete_transient( 'fs_lms_export_' . $token );

		if ( ! @rename( (string) $meta['file'], $target ) ) {
			copy( (string) $meta['file'], $target );
			wp_delete_file( (string) $meta['file'] );
		}
	}

	/**
	 * Форматирует счётчики в одну строку для вывода.
	 *
	 * @param array<string, int> $counts Счётчики
	 *
	 * @return string
	 */
	private function formatCounts( array $counts ): string {
		$parts = array();

		foreach ( $counts as $section => $count ) {
			if ( $count > 0 ) {
				$parts[] = "{$section}: {$count}";
			}
		}

		return $parts ? implode( ', ', $parts ) : 'пусто';
	}
}
