<?php

declare( strict_types=1 );

namespace Inc\Services\Subject\Bundle;

use Inc\Shared\PluginLogger;

/**
 * Class MediaCollector
 *
 * Находит вложения, на которые ссылается контент пакета, и готовит их к упаковке.
 *
 * @package Inc\Services\Subject\Bundle
 *
 * ### Как ищутся вложения
 *
 * Тем же принципом, что и ссылки между записями ({@see RefRemapper}) — по имени
 * ключа в мете: `attachment_ids[]` (материалы задания, файловые задания) и
 * `attachment_id` (аудио-задание). Перечислять пути к каждому полю бессмысленно:
 * поля добавляются в `MetaBoxes/Fields/`, и такой список устаревал бы каждый раз.
 *
 * ### Чего здесь нет
 *
 * Видеозаписи занятий (модуль VideoLibrary, S3) — отдельный домен, не часть
 * контента предмета, и в пакет не входят.
 */
class MediaCollector {

	/**
	 * Ключи меты, содержащие ссылки на вложения.
	 */
	private const array ATTACHMENT_KEYS = array( 'attachment_ids', 'attachment_id' );

	/**
	 * Собирает уникальные ID вложений из мета-структур записей.
	 *
	 * @param array<int, array<string, mixed>> $posts Представления записей (PostCollector)
	 *
	 * @return int[] Уникальные ID вложений
	 */
	public function collectIds( array $posts ): array {
		$ids = array();

		foreach ( $posts as $post ) {
			$this->walk( (array) ( $post['meta'] ?? array() ), $ids );
		}

		sort( $ids );

		return $ids;
	}

	/**
	 * Описывает вложения для манифеста и упаковки.
	 *
	 * Недоступные на диске файлы пропускаются с предупреждением: отсутствие
	 * одного вложения не повод отменять перенос всего предмета — ссылка на него
	 * будет отброшена при импорте, а администратор увидит это в отчёте.
	 *
	 * @param int[] $attachmentIds ID вложений
	 *
	 * @return array{
	 *     manifest: array<int, array<string, mixed>>,
	 *     files: array<int, array{path: string, archive_path: string}>,
	 *     missing: string[]
	 * }
	 */
	public function describe( array $attachmentIds ): array {
		$manifest = array();
		$files    = array();
		$missing  = array();

		foreach ( $attachmentIds as $attachmentId ) {
			$path = get_attached_file( $attachmentId );

			if ( ! is_string( $path ) || '' === $path || ! is_readable( $path ) ) {
				$missing[] = "Вложение #{$attachmentId} недоступно на диске — ссылки на него не переносятся.";
				PluginLogger::warning( 'SUBJECT_BUNDLE', 'Вложение недоступно при экспорте', array( 'attachment_id' => $attachmentId ) );
				continue;
			}

			$filename    = basename( $path );
			$archivePath = BundleSchema::mediaPath( $attachmentId, $filename );

			$manifest[] = array(
				'export_id' => MediaIdMap::exportId( $attachmentId ),
				'file'      => $archivePath,
				'filename'  => $filename,
				'mime'      => (string) get_post_mime_type( $attachmentId ),
				'size'      => (int) filesize( $path ),
				'sha256'    => (string) hash_file( 'sha256', $path ),
			);

			$files[] = array(
				'path'         => $path,
				'archive_path' => $archivePath,
			);
		}

		return array(
			'manifest' => $manifest,
			'files'    => $files,
			'missing'  => $missing,
		);
	}

	/**
	 * Рекурсивно собирает ID вложений из мета-структуры.
	 *
	 * @param array $node Узел меты
	 * @param int[] $ids  Аккумулятор (по ссылке)
	 *
	 * @return void
	 */
	private function walk( array $node, array &$ids ): void {
		foreach ( $node as $key => $value ) {
			if ( in_array( (string) $key, self::ATTACHMENT_KEYS, true ) ) {
				foreach ( (array) $value as $candidate ) {
					$attachmentId = (int) $candidate;
					if ( $attachmentId > 0 && ! in_array( $attachmentId, $ids, true ) ) {
						$ids[] = $attachmentId;
					}
				}
				continue;
			}

			if ( is_array( $value ) ) {
				$this->walk( $value, $ids );
			}
		}
	}
}
