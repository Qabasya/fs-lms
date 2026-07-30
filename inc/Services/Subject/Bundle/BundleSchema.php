<?php

declare( strict_types=1 );

namespace Inc\Services\Subject\Bundle;

/**
 * Class BundleSchema
 *
 * Константы формата пакета переноса предмета (Этап 0).
 *
 * @package Inc\Services\Subject\Bundle
 *
 * ### Структура ZIP
 *
 * ```
 * manifest.json
 * media/{attachment_id}__{filename}
 * ```
 *
 * ### Структура manifest.json
 *
 * ```
 * {
 *   "schema_version": "1.0.0",
 *   "plugin_version": "...",
 *   "exported_at": "2026-07-30 12:00:00",
 *   "site_url": "https://source.example",
 *   "subject": { "key": "...", "name": "...", "hasBank": true },
 *   "taxonomies": {...}, "metaboxes": {...}, "boilerplates": {...}, "terms": {...},
 *   "posts": {
 *     "tasks": [...], "articles": [...], "problems": [...], "works": [...],
 *     "assessments": [...], "lessons": [...], "courses": [...]
 *   },
 *   "media": [ { "export_id": "media:123", "file": "media/123__photo.jpg",
 *                "filename": "photo.jpg", "mime": "image/jpeg",
 *                "size": 12345, "sha256": "…" } ],
 *   "students": { "persons": [...], "records": [...], "groups": [...] }
 * }
 * ```
 *
 * ### Версионирование
 *
 * `schema_version` живёт по semver и проверяется при импорте по мажорной части:
 * пакет с другим major отвергается целиком (формат несовместим), больший minor
 * принимается — новые поля старый импортёр просто игнорирует. Это позволяет
 * развивать формат, не ломая уже выгруженные архивы.
 */
final class BundleSchema {

	/** Текущая версия формата пакета. */
	public const string VERSION = '1.0.0';

	/** Имя файла манифеста в корне архива. */
	public const string MANIFEST = 'manifest.json';

	/** Каталог медиафайлов внутри архива (со слешем). */
	public const string MEDIA_DIR = 'media/';

	/** Расширение файла пакета. */
	public const string EXTENSION = 'zip';

	/** MIME-тип пакета для заголовка отдачи файла. */
	public const string MIME = 'application/zip';

	/**
	 * Ключ стабильного экспортного идентификатора внутри записи манифеста.
	 *
	 * WP ID на целевом сайте будет другим, поэтому все ссылки в пакете хранятся
	 * как `_export_id` вида `tasks:123` ({@see ExportIdMapper}).
	 */
	public const string EXPORT_ID = '_export_id';

	/**
	 * Мажорная часть версии — единица совместимости формата.
	 *
	 * @param string $version Строка версии (`1.2.3`)
	 *
	 * @return int Мажорная часть; 0 при неразборчивой строке
	 */
	public static function major( string $version ): int {
		return (int) ( explode( '.', trim( $version ) )[0] ?? 0 );
	}

	/**
	 * Совместим ли пакет с текущим импортёром.
	 *
	 * @param string $version Версия формата из манифеста
	 *
	 * @return bool
	 */
	public static function isCompatible( string $version ): bool {
		return self::major( $version ) === self::major( self::VERSION );
	}

	/**
	 * Путь медиафайла внутри архива.
	 *
	 * Префикс из attachment_id гарантирует уникальность имени: два разных
	 * вложения вполне могут называться `photo.jpg`.
	 *
	 * @param int    $attachmentId ID вложения на сайте-источнике
	 * @param string $filename     Имя файла (basename)
	 *
	 * @return string Относительный путь внутри архива
	 */
	public static function mediaPath( int $attachmentId, string $filename ): string {
		return self::MEDIA_DIR . $attachmentId . '__' . sanitize_file_name( $filename );
	}
}
