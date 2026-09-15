<?php

declare( strict_types=1 );

namespace Inc\Services\Subject\Bundle;

/**
 * Class MediaUrlRewriter
 *
 * Переписывает ссылки на медиа внутри текста записи: URL и `wp-image-{id}`
 * сайта-источника → адреса залитых копий.
 *
 * @package Inc\Services\Subject\Bundle
 *
 * ### Зачем отдельно от RefRemapper
 *
 * `RefRemapper` работает со ссылками-идентификаторами: значение по известному
 * ключу меты — это ID, его достаточно заменить числом. Здесь ссылка вкраплена в
 * произвольный текст — HTML условия (`<img class="wp-image-143" src="…">`) или
 * URL-поле `LinkField` — и заменять приходится подстроку, а не значение.
 *
 * ### Почему таблица замен, а не плейсхолдеры в пакете
 *
 * Экспорт мог бы вырезать URL из HTML и подставить `media:123`, но тогда любой
 * непереносимый файл ломал бы разметку условия. Пакет хранит текст как есть, а
 * замену делает импорт: чего в карте нет — остаётся нетронутым и попадает в
 * предупреждения ({@see MediaCollector::unresolvedUrls()} на стороне экспорта).
 *
 * `strtr()` с массивом выбирает самое длинное совпадение и не применяет замены
 * повторно к уже заменённому — поэтому порядок ключей значения не имеет.
 *
 * ### ID в атрибутах шорткодов
 *
 * `[vc_single_image image="143"]` подстрокой не заменить: `image="143"` встречается и у чужих
 * записей, а в `images="143,150"` ID стоят списком. Для них отдельная карта «старый ID → новый»
 * ({@see buildIdMap()}), замену делает {@see ShortcodeMediaRefs::rewrite()}.
 */
final class MediaUrlRewriter {

	/**
	 * Поля записи, в которых переписываются ссылки помимо меты.
	 */
	private const array REWRITTEN_POST_FIELDS = array( 'post_content', 'post_excerpt' );

	/**
	 * @param ShortcodeMediaRefs $shortcodes Ссылки на вложения в атрибутах шорткодов
	 */
	public function __construct(
		private readonly ShortcodeMediaRefs $shortcodes = new ShortcodeMediaRefs(),
	) {}

	/**
	 * Строит таблицу замен по разделу `media[]` манифеста.
	 *
	 * @param array      $media    Раздел `media[]` манифеста
	 * @param MediaIdMap $idMap    Карта `_export_id` → новый attachment ID
	 *
	 * @return array<string, string> Старая подстрока → новая
	 */
	public function buildMap( array $media, MediaIdMap $idMap ): array {
		$replacements = array();

		foreach ( $media as $entry ) {
			$newId = $idMap->resolve( (string) ( $entry['export_id'] ?? '' ) );
			if ( null === $newId || $newId <= 0 ) {
				continue;
			}

			$sourceId = (int) ( $entry['source_id'] ?? 0 );
			if ( $sourceId > 0 && $sourceId !== $newId ) {
				$replacements[ 'wp-image-' . $sourceId ] = 'wp-image-' . $newId;
			}

			foreach ( (array) ( $entry['source_urls'] ?? array() ) as $size => $oldUrl ) {
				$oldUrl = (string) $oldUrl;
				$newUrl = $this->targetUrl( $newId, (string) $size );

				if ( '' !== $oldUrl && '' !== $newUrl && $oldUrl !== $newUrl ) {
					$replacements[ $oldUrl ] = $newUrl;
				}
			}
		}

		return $replacements;
	}

	/**
	 * Карта ID вложений для атрибутов шорткодов по разделу `media[]` манифеста.
	 *
	 * @param array      $media Раздел `media[]` манифеста
	 * @param MediaIdMap $idMap Карта `_export_id` → новый attachment ID
	 *
	 * @return array<int, int> ID на источнике → ID на целевом сайте
	 */
	public function buildIdMap( array $media, MediaIdMap $idMap ): array {
		$ids = array();

		foreach ( $media as $entry ) {
			$sourceId = (int) ( $entry['source_id'] ?? 0 );
			$newId    = $idMap->resolve( (string) ( $entry['export_id'] ?? '' ) );

			if ( $sourceId > 0 && null !== $newId && $newId > 0 ) {
				$ids[ $sourceId ] = $newId;
			}
		}

		return $ids;
	}

	/**
	 * Применяет таблицу замен и карту ID к представлению записи.
	 *
	 * @param array<string, mixed>  $post         Представление записи из манифеста
	 * @param array<string, string> $replacements Таблица замен подстрок ({@see buildMap()})
	 * @param array<int, int>       $idMap        ID вложений для шорткодов ({@see buildIdMap()})
	 *
	 * @return array<string, mixed> Представление с переписанными ссылками
	 */
	public function rewritePost( array $post, array $replacements, array $idMap = array() ): array {
		if ( array() === $replacements && array() === $idMap ) {
			return $post;
		}

		$post['meta'] = $this->rewriteValue( (array) ( $post['meta'] ?? array() ), $replacements, $idMap );

		foreach ( self::REWRITTEN_POST_FIELDS as $field ) {
			if ( isset( $post[ $field ] ) && is_string( $post[ $field ] ) ) {
				$post[ $field ] = $this->rewriteText( $post[ $field ], $replacements, $idMap );
			}
		}

		return $post;
	}

	/**
	 * Переписывает одну строку: сначала подстроки, затем ID в атрибутах шорткодов.
	 *
	 * @param string                $text         Текст
	 * @param array<string, string> $replacements Таблица замен подстрок
	 * @param array<int, int>       $idMap        ID вложений для шорткодов
	 *
	 * @return string
	 */
	private function rewriteText( string $text, array $replacements, array $idMap ): string {
		if ( array() !== $replacements ) {
			$text = strtr( $text, $replacements );
		}

		return $this->shortcodes->rewrite( $text, $idMap );
	}

	/**
	 * Рекурсивно переписывает строки внутри структуры меты.
	 *
	 * @param mixed                 $value        Узел меты
	 * @param array<string, string> $replacements Таблица замен
	 * @param array<int, int>       $idMap        ID вложений для шорткодов
	 *
	 * @return mixed Узел с переписанными строками
	 */
	private function rewriteValue( mixed $value, array $replacements, array $idMap ): mixed {
		if ( is_string( $value ) ) {
			return $this->rewriteText( $value, $replacements, $idMap );
		}

		if ( ! is_array( $value ) ) {
			return $value;
		}

		foreach ( $value as $key => $item ) {
			$value[ $key ] = $this->rewriteValue( $item, $replacements, $idMap );
		}

		return $value;
	}

	/**
	 * URL нового вложения в том же размере, что был на источнике.
	 *
	 * Размер мог не сгенерироваться (другой набор image sizes на целевом сайте) —
	 * тогда ссылка ведёт на оригинал: картинка крупнее ожидаемой лучше битой.
	 *
	 * @param int    $attachmentId Новый ID вложения
	 * @param string $size         Имя размера из манифеста
	 *
	 * @return string
	 */
	private function targetUrl( int $attachmentId, string $size ): string {
		if ( '' !== $size && 'full' !== $size ) {
			$sized = wp_get_attachment_image_url( $attachmentId, $size );
			if ( is_string( $sized ) && '' !== $sized ) {
				return $sized;
			}
		}

		$full = wp_get_attachment_url( $attachmentId );

		return is_string( $full ) ? $full : '';
	}
}
