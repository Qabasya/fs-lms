<?php

declare( strict_types=1 );

namespace Inc\Modules\EgeComputer\Services;

use Inc\DTO\Assessment\AssessmentDTO;
use Inc\Services\Assessment\AttemptTaskViewBuilder;
use ZipArchive;

/**
 * Class KegeMaterialsZipService
 *
 * Архив «Скачать все файлы» станции: материалы всех заданий работы одним ZIP.
 * Файлы берутся те же, что показаны на панелях заданий ({@see AttemptTaskViewBuilder}),
 * только свои — из медиатеки сайта; внешние ссылки в архив не попадают.
 *
 * Архив собирается один раз и лежит в `uploads/fs-lms-kege-zips/`: имя содержит отпечаток
 * набора файлов и их дат, поэтому правка файла задания даёт новый архив, а старый
 * убирается. Так повторные скачивания (весь класс разом) не пересобирают ZIP.
 *
 * @package Inc\Modules\EgeComputer\Services
 */
class KegeMaterialsZipService {

	private const DIR = 'fs-lms-kege-zips';

	public function __construct(
		private readonly AttemptTaskViewBuilder $taskViews,
	) {}

	/**
	 * Адрес ZIP со всеми файлами работы; '' — файлов нет (или собрать архив нельзя).
	 */
	public function urlFor( AssessmentDTO $assessment ): string {
		$files = $this->files( $assessment );
		if ( empty( $files ) || ! class_exists( ZipArchive::class ) ) {
			return '';
		}

		$uploads = wp_get_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . self::DIR;
		if ( ! wp_mkdir_p( $dir ) ) {
			return '';
		}

		$stamp = md5( wp_json_encode( array_map( static fn( array $f ): array => array( $f['path'], (int) filemtime( $f['path'] ) ), $files ) ) );
		$name  = sprintf( 'kege-%d-%s.zip', $assessment->id, $stamp );
		$path  = $dir . '/' . $name;

		if ( ! is_file( $path ) && ! $this->build( $path, $files ) ) {
			return '';
		}

		$this->removeStale( $dir, $assessment->id, $name );

		return trailingslashit( $uploads['baseurl'] ) . self::DIR . '/' . $name;
	}

	/**
	 * Локальные файлы материалов в порядке заданий работы, без повторов.
	 *
	 * @return list<array{path: string, name: string}>
	 */
	private function files( AssessmentDTO $assessment ): array {
		$uploads    = wp_get_upload_dir();
		$basePath   = (string) wp_parse_url( $uploads['baseurl'], PHP_URL_PATH );
		$views      = $this->taskViews->build( $assessment->taskIds, $assessment->subjectKey, $assessment->kind );

		$files = array();
		$seen  = array();
		foreach ( $assessment->taskIds as $taskId ) {
			foreach ( ( $views[ (int) $taskId ]['materials'] ?? array() ) as $material ) {
				$url = (string) $material['url'];
				if ( isset( $seen[ $url ] ) ) {
					continue;
				}

				// Сравниваем путь, а не весь адрес: ссылки в мете заданий могут быть
				// записаны с другим хостом (прежний домен, IP), файл при этом свой.
				$urlPath = rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) );
				if ( '' === $basePath || ! str_starts_with( $urlPath, $basePath . '/' ) ) {
					continue;
				}

				$path = $uploads['basedir'] . substr( $urlPath, strlen( $basePath ) );
				if ( str_contains( $path, '..' ) || ! is_file( $path ) ) {
					continue;
				}

				$seen[ $url ] = true;
				$files[]      = array( 'path' => $path, 'name' => basename( $path ) );
			}
		}

		return $files;
	}

	/**
	 * @param list<array{path: string, name: string}> $files
	 */
	private function build( string $path, array $files ): bool {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return false;
		}

		$used = array();
		foreach ( $files as $file ) {
			$entry = $file['name'];
			// Одноимённые файлы разных заданий не затирают друг друга: file.csv, file-2.csv…
			for ( $n = 2; isset( $used[ $entry ] ); $n++ ) {
				$info  = pathinfo( $file['name'] );
				$entry = $info['filename'] . '-' . $n . ( isset( $info['extension'] ) ? '.' . $info['extension'] : '' );
			}
			$used[ $entry ] = true;
			$zip->addFile( $file['path'], $entry );
		}

		return $zip->close();
	}

	/** Убирает прежние архивы этой работы (набор файлов изменился). */
	private function removeStale( string $dir, int $assessmentId, string $keep ): void {
		foreach ( glob( $dir . '/kege-' . $assessmentId . '-*.zip' ) ?: array() as $old ) {
			if ( basename( $old ) !== $keep ) {
				wp_delete_file( $old );
			}
		}
	}
}
