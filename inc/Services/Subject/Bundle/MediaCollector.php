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
 * Двумя способами, потому что поля хранят вложения двояко.
 *
 * 1. **По имени ключа** — тем же принципом, что и ссылки между записями
 *    ({@see RefRemapper}): `attachment_ids[]` (материалы задания, файловые
 *    задания) и `attachment_id` (аудио-задание). Перечислять пути к каждому
 *    полю бессмысленно: поля добавляются в `MetaBoxes/Fields/`, и такой список
 *    устаревал бы каждый раз.
 * 2. **По содержимому строк** — картинка условия (`ConditionField`) вставляется
 *    медиакнопкой как обычный HTML `<img class="wp-image-143" src="…">`, а
 *    `LinkField` (`file`, `file_primary`, `file_secondary`) хранит прямой URL.
 *    Ни там, ни там ID вложения отдельным ключом не лежит, поэтому строки
 *    сканируются на `wp-image-{id}` и на URL внутри своего `uploads`. Без этого
 *    файл не попадал в пакет, а на целевом сайте ссылка продолжала указывать на
 *    сайт-источник.
 *
 * Найденные по строкам вложения переносятся вместе с картой старых URL
 * ({@see describe()}), по которой импорт переписывает ссылки
 * ({@see MediaUrlRewriter}).
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
	 * Поля записи, которые сканируются на ссылки помимо меты.
	 */
	private const array SCANNED_POST_FIELDS = array( 'post_content', 'post_excerpt' );

	/**
	 * Кеш резолва URL → ID вложения (в одном пакете один URL встречается многажды).
	 *
	 * @var array<string, int>
	 */
	private array $urlCache = array();

	/**
	 * URL внутри своего uploads, которые не удалось связать с вложением.
	 *
	 * @var array<string, true>
	 */
	private array $unresolved = array();

	/**
	 * Базовый URL каталога загрузок (без схемы), вычисляется один раз.
	 */
	private ?string $uploadsBase = null;

	/**
	 * Собирает уникальные ID вложений из записей: мета + контент.
	 *
	 * @param array<int, array<string, mixed>> $posts Представления записей (PostCollector)
	 *
	 * @return int[] Уникальные ID вложений
	 */
	public function collectIds( array $posts ): array {
		// Сборщик — singleton контейнера, а в одном процессе (WP-CLI) экспортов
		// может быть несколько: накопленное от прошлого пакета здесь ни к чему.
		$this->urlCache   = array();
		$this->unresolved = array();

		$ids = array();

		foreach ( $posts as $post ) {
			$this->walk( (array) ( $post['meta'] ?? array() ), $ids );

			foreach ( self::SCANNED_POST_FIELDS as $field ) {
				$this->scanText( (string) ( $post[ $field ] ?? '' ), $ids );
			}
		}

		sort( $ids );

		return $ids;
	}

	/**
	 * Ссылки на свой uploads, которым не нашлось вложения (для отчёта экспорта).
	 *
	 * Файл мог быть удалён из медиабиблиотеки, но остаться в HTML условия —
	 * перенести его нечем, и администратор должен об этом узнать.
	 *
	 * @return string[]
	 */
	public function unresolvedUrls(): array {
		return array_map(
			static fn( string $url ): string => "Ссылка «{$url}» не связана ни с одним вложением — файл в пакет не попал.",
			array_keys( $this->unresolved )
		);
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
				'export_id'   => MediaIdMap::exportId( $attachmentId ),
				'file'        => $archivePath,
				'filename'    => $filename,
				'mime'        => (string) get_post_mime_type( $attachmentId ),
				'size'        => (int) filesize( $path ),
				'sha256'      => (string) hash_file( 'sha256', $path ),
				// Старые ID и URL нужны импорту: по ним переписываются ссылки в
				// HTML условия и в URL-полях (см. MediaUrlRewriter).
				'source_id'   => $attachmentId,
				'source_urls' => $this->urlVariants( $attachmentId ),
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
				continue;
			}

			if ( is_string( $value ) ) {
				$this->scanText( $value, $ids );
			}
		}
	}

	/**
	 * Ищет вложения в тексте: класс `wp-image-{id}` и URL внутри своего uploads.
	 *
	 * @param string $text Строковое значение меты или контент записи
	 * @param int[]  $ids  Аккумулятор (по ссылке)
	 *
	 * @return void
	 */
	private function scanText( string $text, array &$ids ): void {
		if ( '' === $text ) {
			return;
		}

		$base = $this->uploadsBase();

		// Дешёвый отсев: подавляющее большинство строк меты (ответы, коды,
		// подсказки) не содержат ни того, ни другого.
		$hasClass = str_contains( $text, 'wp-image-' );
		$hasUrl   = '' !== $base && str_contains( $text, $base );

		if ( ! $hasClass && ! $hasUrl ) {
			return;
		}

		if ( $hasClass && preg_match_all( '/wp-image-(\d+)/', $text, $matches ) ) {
			foreach ( $matches[1] as $candidate ) {
				$this->pushAttachment( (int) $candidate, $ids );
			}
		}

		if ( ! $hasUrl || ! preg_match_all( '#https?://[^\s"\'<>()\\\\]+#i', $text, $matches ) ) {
			return;
		}

		foreach ( $matches[0] as $url ) {
			$this->pushAttachment( $this->resolveUrl( (string) $url ), $ids );
		}
	}

	/**
	 * Добавляет ID в аккумулятор, если это действительно вложение.
	 *
	 * Числа из строк — материал ненадёжный (`wp-image-` мог остаться от чужого
	 * сайта), поэтому в отличие от ключей `attachment_id` тип записи проверяется.
	 *
	 * @param int   $attachmentId Кандидат
	 * @param int[] $ids          Аккумулятор (по ссылке)
	 *
	 * @return void
	 */
	private function pushAttachment( int $attachmentId, array &$ids ): void {
		if ( $attachmentId <= 0 || in_array( $attachmentId, $ids, true ) ) {
			return;
		}

		if ( 'attachment' !== get_post_type( $attachmentId ) ) {
			return;
		}

		$ids[] = $attachmentId;
	}

	/**
	 * Связывает URL с вложением своей медиабиблиотеки.
	 *
	 * @param string $url Ссылка из текста
	 *
	 * @return int ID вложения; 0 — чужой домен или файл не в библиотеке
	 */
	private function resolveUrl( string $url ): int {
		$base = $this->uploadsBase();
		if ( '' === $base || ! str_contains( $this->stripScheme( $url ), $base ) ) {
			return 0;
		}

		$url = html_entity_decode( $url, ENT_QUOTES );

		if ( isset( $this->urlCache[ $url ] ) ) {
			return $this->urlCache[ $url ];
		}

		$resolved = (int) attachment_url_to_postid( $url );

		// Ссылка обычно ведёт на превью (`-300x183`), а вложением зарегистрирован
		// оригинал — снимаем суффикс размера и пробуем ещё раз.
		if ( 0 === $resolved ) {
			$original = (string) preg_replace( '/-\d+x\d+(?=\.[A-Za-z0-9]+$)/', '', $url );
			if ( $original !== $url ) {
				$resolved = (int) attachment_url_to_postid( $original );
			}
		}

		if ( 0 === $resolved ) {
			$this->unresolved[ $url ] = true;
		}

		$this->urlCache[ $url ] = $resolved;

		return $resolved;
	}

	/**
	 * Все URL вложения: оригинал и превью каждого зарегистрированного размера.
	 *
	 * Именно превью стоит в `src` картинки условия, поэтому одного полного URL
	 * для переписывания ссылок не хватает.
	 *
	 * @param int $attachmentId ID вложения
	 *
	 * @return array<string, string> Размер → URL
	 */
	private function urlVariants( int $attachmentId ): array {
		$variants = array();

		$full = wp_get_attachment_url( $attachmentId );
		if ( is_string( $full ) && '' !== $full ) {
			$variants['full'] = $full;
		}

		$meta = wp_get_attachment_metadata( $attachmentId );
		foreach ( array_keys( (array) ( $meta['sizes'] ?? array() ) ) as $size ) {
			$url = wp_get_attachment_image_url( $attachmentId, (string) $size );
			if ( is_string( $url ) && '' !== $url ) {
				$variants[ (string) $size ] = $url;
			}
		}

		return $variants;
	}

	/**
	 * Базовый URL каталога загрузок без схемы.
	 *
	 * Схема отбрасывается намеренно: в контенте вперемешку встречаются `http://`
	 * и `https://` варианты одного и того же адреса.
	 *
	 * @return string
	 */
	private function uploadsBase(): string {
		if ( null === $this->uploadsBase ) {
			$dir               = wp_get_upload_dir();
			$this->uploadsBase = $this->stripScheme( (string) ( $dir['baseurl'] ?? '' ) );
		}

		return $this->uploadsBase;
	}

	/**
	 * Убирает протокол из URL.
	 *
	 * @param string $url Ссылка
	 *
	 * @return string
	 */
	private function stripScheme( string $url ): string {
		return (string) preg_replace( '#^https?://#i', '', $url );
	}
}
