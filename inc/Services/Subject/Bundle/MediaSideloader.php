<?php

declare( strict_types=1 );

namespace Inc\Services\Subject\Bundle;

use Inc\Services\Subject\Import\ImportedEntitiesCollector;
use Inc\Shared\Traits\ScopedFilter;
use Inc\Shared\PluginLogger;

/**
 * Class MediaSideloader
 *
 * Заливает медиафайлы пакета в медиабиблиотеку целевого сайта (Этап 4).
 *
 * @package Inc\Services\Subject\Bundle
 *
 * ### Порядок
 *
 * Медиа заливается ДО вставки записей: тогда к моменту записи меты все новые
 * attachment ID уже известны, и мету не приходится переписывать вторым проходом.
 *
 * ### Контроль типов
 *
 * Белый список MIME намеренно совпадает с {@see \Inc\Managers\Wp\MediaManager}
 * и не расширяется ради импорта: пакет — такой же пользовательский ввод, как
 * загрузка файла через форму, и ослаблять для него правила нельзя. Проверяется
 * реальный тип содержимого (`finfo`), а не расширение из архива.
 *
 * Файл недопустимого типа пропускается с предупреждением, а не роняет импорт:
 * экспорт кладёт в пакет всё, на что ссылается контент (включая файлы, залитые
 * мимо форм плагина — прямо в медиатеку), поэтому один нестандартный файл не
 * должен отменять перенос всего предмета. Ссылка на него остаётся исходным URL —
 * то же поведение, что и для отсутствующего в пакете медиа.
 */
class MediaSideloader {

	use ScopedFilter;

	/**
	 * Допустимые MIME-типы — зеркало {@see \Inc\Managers\Wp\MediaManager}.
	 */
	private const array ALLOWED_MIME_TYPES = array(
		'image/jpeg',
		'image/png',
		'image/gif',
		'image/webp',
		'image/heic',
		'image/heif',
		'application/pdf',
		'application/msword',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'application/vnd.openxmlformats-officedocument.presentationml.presentation',
		'application/vnd.ms-excel',
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'application/vnd.oasis.opendocument.text',
		'application/vnd.oasis.opendocument.presentation',
		'text/plain',
		'text/csv',
		'application/csv',
		'text/x-python',
		'application/zip',
		'application/x-zip-compressed',
	);

	/**
	 * Заливает все файлы пакета и возвращает карту `_export_id` → новый ID.
	 *
	 * @param array               $media      Раздел `media[]` манифеста
	 * @param string              $extractDir Каталог распаковки
	 * @param ImportedEntitiesCollector $created    Журнал созданного (для отката)
	 *
	 * @return array{map: MediaIdMap, warnings: string[]}
	 */
	public function sideloadAll( array $media, string $extractDir, ImportedEntitiesCollector $created ): array {
		$map      = new MediaIdMap();
		$warnings = array();

		if ( array() === $media ) {
			return array(
				'map'      => $map,
				'warnings' => $warnings,
			);
		}

		$this->requireWpMediaApi();

		foreach ( $media as $entry ) {
			$exportId = (string) ( $entry['export_id'] ?? '' );
			$relative = (string) ( $entry['file'] ?? '' );

			if ( '' === $exportId || '' === $relative ) {
				continue;
			}

			$path = rtrim( $extractDir, '/\\' ) . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
			if ( ! is_readable( $path ) ) {
				$warnings[] = "Медиафайл «{$relative}» отсутствует в архиве — ссылки на него не восстановлены.";
				continue;
			}

			$rejection = $this->rejectionReason( $path, $relative );
			if ( null !== $rejection ) {
				$warnings[] = $rejection;
				continue;
			}

			$attachmentId = $this->sideload( $path, (string) ( $entry['filename'] ?? basename( $path ) ) );

			if ( $attachmentId <= 0 ) {
				$warnings[] = "Не удалось загрузить медиафайл «{$relative}» в медиабиблиотеку.";
				continue;
			}

			$created->addAttachment( $attachmentId );
			$map->bind( $exportId, $attachmentId );
		}

		return array(
			'map'      => $map,
			'warnings' => $warnings,
		);
	}

	/**
	 * Заливает один файл, не трогая оригинал в каталоге распаковки.
	 *
	 * `media_handle_sideload()` перемещает переданный файл, поэтому подсовываем
	 * ему копию: каталог распаковки нужен целиком до конца импорта (проверки,
	 * возможный повтор шага), и «съеденный» оригинал сломал бы это.
	 *
	 * @param string $path     Путь к файлу в каталоге распаковки
	 * @param string $filename Желаемое имя файла
	 *
	 * @return int ID вложения; 0 при ошибке
	 */
	private function sideload( string $path, string $filename ): int {
		$tmp = wp_tempnam( $filename );
		if ( ! $tmp || ! copy( $path, $tmp ) ) {
			return 0;
		}

		$extraMimes = static function ( array $mimes ): array {
			$mimes['py']   = 'text/x-python';
			$mimes['heic'] = 'image/heic';
			$mimes['heif'] = 'image/heif';
			return $mimes;
		};

		$attachmentId = $this->withFilter(
			'upload_mimes',
			$extraMimes,
			static function () use ( $filename, $tmp ) {
				try {
					return media_handle_sideload(
						array(
							'name'     => sanitize_file_name( $filename ),
							'tmp_name' => $tmp,
						),
						0
					);
				} catch ( \Throwable $e ) {
					PluginLogger::exception( 'SUBJECT_BUNDLE', $e, array( 'file' => $filename ), true );

					return null;
				}
			}
		);

		if ( is_wp_error( $attachmentId ) || ! is_int( $attachmentId ) ) {
			// media_handle_sideload() удаляет tmp сам только при успехе.
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			return 0;
		}

		return $attachmentId;
	}

	/**
	 * Возвращает причину отказа для файла с недопустимым типом содержимого.
	 *
	 * @param string $path     Путь к файлу
	 * @param string $relative Имя внутри архива (для сообщения)
	 *
	 * @return string|null Текст предупреждения; null, если тип допустим
	 */
	private function rejectionReason( string $path, string $relative ): ?string {
		$type = mime_content_type( $path );

		if ( is_string( $type ) && in_array( $type, self::ALLOWED_MIME_TYPES, true ) ) {
			return null;
		}

		return sprintf(
			'Медиафайл «%s» пропущен: недопустимый тип (%s) — ссылки на него не восстановлены.',
			$relative,
			(string) $type
		);
	}

	/**
	 * Подключает WP-API работы с медиа (в AJAX-контексте оно не загружено).
	 *
	 * @return void
	 */
	private function requireWpMediaApi(): void {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
	}
}
