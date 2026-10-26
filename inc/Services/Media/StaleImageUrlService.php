<?php

declare( strict_types=1 );

namespace Inc\Services\Media;

/**
 * Class StaleImageUrlService
 *
 * Чинит адреса картинок в HTML, когда файл по адресу исчез.
 *
 * Условия заданий хранят адрес картинки прямо в тексте (в мете задания). Оптимизаторы изображений (TheDock Media и
 * подобные) заменяют `…-scaled.png` и размеры `…-300x100.png` на `.avif`/`.webp` и обновляют вложение, но не тексты
 * записей и не метаполя, — адрес в условии ведёт в 404, и картинка пропадает. Здесь при выводе такой `<img>` получает адрес
 * существующего файла: сначала по ID вложения из класса `wp-image-N` (тот же размер, если он есть), затем по соседнему файлу
 * с тем же именем (`.avif`, `.webp`), затем по несжатому оригиналу без `-scaled`. Если файл на месте, тег не меняется.
 * `srcset` и `sizes` у исправленной картинки убираются: их адреса устарели так же.
 *
 * @package Inc\Services\Media
 */
class StaleImageUrlService {

	/** Форматы, в которые оптимизаторы переводят картинки, в порядке предпочтения. */
	private const MODERN_EXTENSIONS = array( 'avif', 'webp' );

	/** Найденный (или не найденный — null) адрес на время запроса: путь относительно uploads → адрес. */
	private array $resolved = array();

	/**
	 * @param string $html Фрагмент HTML (условие, статья, подсказка)
	 *
	 * @return string HTML, где у картинок с пропавшим файлом адрес заменён на существующий
	 */
	public function repair( string $html ): string {
		if ( ! str_contains( $html, '<img' ) ) {
			return $html;
		}

		$upload = wp_get_upload_dir();
		$base   = rtrim( (string) ( $upload['baseurl'] ?? '' ), '/' );
		$dir    = rtrim( (string) ( $upload['basedir'] ?? '' ), '/' );
		if ( '' === $base || '' === $dir ) {
			return $html;
		}

		return (string) preg_replace_callback(
			'/<img\b[^>]*>/i',
			fn ( array $m ): string => $this->repairTag( $m[0], $base, $dir ),
			$html
		);
	}

	private function repairTag( string $tag, string $base, string $dir ): string {
		if ( ! preg_match( '/\ssrc=(["\'])(.*?)\1/i', $tag, $src ) || ! str_starts_with( $src[2], $base . '/' ) ) {
			return $tag;
		}

		$rel = rawurldecode( (string) strtok( substr( $src[2], strlen( $base ) + 1 ), '?#' ) );
		if ( '' === $rel || file_exists( $dir . '/' . $rel ) ) {
			return $tag;
		}

		$attachmentId = preg_match( '/\bwp-image-(\d+)\b/', $tag, $id ) ? (int) $id[1] : 0;
		$fixed        = $this->resolved[ $rel ] ??= $this->find( $rel, $attachmentId, $base, $dir );
		if ( null === $fixed ) {
			return $tag;
		}

		$tag = (string) preg_replace( '/\ssrc=(["\']).*?\1/i', ' src="' . esc_url( $fixed ) . '"', $tag, 1 );

		return (string) preg_replace( '/\s(?:srcset|sizes)=(["\']).*?\1/i', '', $tag );
	}

	/** Адрес существующего файла вместо пропавшего или null. */
	private function find( string $rel, int $attachmentId, string $base, string $dir ): ?string {
		$candidates = array_merge( $this->byAttachment( $rel, $attachmentId ), $this->bySiblings( $rel ) );
		foreach ( $candidates as $candidate ) {
			if ( file_exists( $dir . '/' . $candidate ) ) {
				return $base . '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $candidate ) ) );
			}
		}

		return null;
	}

	/**
	 * Пути из данных вложения: тот же размер (по ширине и высоте из имени `…-300x100.png`), иначе основной файл.
	 *
	 * @return list<string>
	 */
	private function byAttachment( string $rel, int $attachmentId ): array {
		if ( $attachmentId <= 0 ) {
			return array();
		}

		$meta = wp_get_attachment_metadata( $attachmentId );
		$main = is_array( $meta ) && ! empty( $meta['file'] ) ? (string) $meta['file'] : '';
		if ( '' === $main ) {
			return array();
		}

		$out = array();
		if ( preg_match( '/-(\d+)x(\d+)\.[a-z0-9]+$/i', $rel, $size ) ) {
			foreach ( (array) ( $meta['sizes'] ?? array() ) as $variant ) {
				if ( (int) ( $variant['width'] ?? 0 ) === (int) $size[1] && (int) ( $variant['height'] ?? 0 ) === (int) $size[2] && ! empty( $variant['file'] ) ) {
					$out[] = ltrim( dirname( $main ) . '/' . $variant['file'], './' );
				}
			}
		}
		$out[] = $main;

		return $out;
	}

	/**
	 * Соседние файлы с тем же именем в новых форматах, затем несжатый оригинал без `-scaled`.
	 *
	 * @return list<string>
	 */
	private function bySiblings( string $rel ): array {
		$info = pathinfo( $rel );
		$dir  = '.' === ( $info['dirname'] ?? '.' ) ? '' : $info['dirname'] . '/';
		$name = (string) $info['filename'];
		$ext  = (string) ( $info['extension'] ?? '' );

		$out = array();
		foreach ( self::MODERN_EXTENSIONS as $modern ) {
			$out[] = $dir . $name . '.' . $modern;
		}

		if ( str_ends_with( $name, '-scaled' ) ) {
			$original = substr( $name, 0, -7 );
			foreach ( self::MODERN_EXTENSIONS as $modern ) {
				$out[] = $dir . $original . '.' . $modern;
			}
			$out[] = $dir . $original . '.' . $ext;
		}

		return $out;
	}
}
