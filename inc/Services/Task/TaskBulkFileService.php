<?php

declare( strict_types=1 );

namespace Inc\Services\Task;

/**
 * Class TaskBulkFileService
 *
 * Переименование файлов заданий под новый номер при массовой правке: `24-123123.txt` → `24003.txt`.
 *
 * Файлы лежат в каталоге загрузок, а задание хранит на них ссылки в полях `file`,
 * `file_primary`, `file_secondary` меты {@see \Inc\Enums\Wp\PostMetaName::Meta}. Имя файла —
 * номер задания (для двух файлов — с суффиксом `-1`/`-2`), расширение сохраняется.
 *
 * Правила безопасности:
 * - трогаются только файлы из каталога загрузок этого сайта; чужие ссылки остаются как есть;
 * - файл, на который ссылается ещё какое-то задание, копируется, а не переносится;
 * - вложение медиатеки с миниатюрами не переименовывается (миниатюры осиротели бы);
 * - перенос двухфазный (через временное имя), поэтому обмен именами между двумя заданиями работает.
 *
 * @package Inc\Services\Task
 */
class TaskBulkFileService {

	/** Поле меты → суффикс имени файла. */
	public const array KEYS = array(
		'file'           => '',
		'file_primary'   => '-1',
		'file_secondary' => '-2',
	);

	public const string MODE_MOVE = 'move';
	public const string MODE_COPY = 'copy';

	/**
	 * Операции с файлами задания под новый номер.
	 *
	 * @param array<string, mixed> $meta    Мета задания (fs_lms_meta)
	 * @param string               $newSlug Новый номер
	 * @param int                  $postId  ID задания (его собственные ссылки на файл не считаются «чужими»)
	 *
	 * @return array{ops: array<int, array<string, mixed>>, warnings: string[]}
	 */
	public function plan( array $meta, string $newSlug, int $postId ): array {
		$ops      = array();
		$warnings = array();

		foreach ( self::KEYS as $key => $suffix ) {
			$url = $meta[ $key ] ?? '';
			if ( ! is_string( $url ) || '' === $url ) {
				continue;
			}

			$from = $this->urlToPath( $url );
			if ( null === $from ) {
				continue; // Внешняя ссылка — не наш файл.
			}

			$ext  = pathinfo( $from, PATHINFO_EXTENSION );
			$name = $newSlug . $suffix . ( '' !== $ext ? '.' . $ext : '' );
			if ( basename( $from ) === $name ) {
				continue;
			}

			if ( ! is_file( $from ) ) {
				$warnings[] = "файл {$key} не найден на диске ({$url}) — не переименован";
				continue;
			}

			$attachmentId = (int) attachment_url_to_postid( $url );
			if ( $attachmentId > 0 && $this->hasThumbnails( $attachmentId ) ) {
				$warnings[] = 'файл ' . basename( $from ) . ' — изображение медиатеки с миниатюрами, не переименован';
				continue;
			}

			$to = dirname( $from ) . '/' . $name;

			$ops[] = array(
				'key'           => $key,
				'from'          => $from,
				'to'            => $to,
				'old_url'       => $url,
				'new_url'       => $this->pathToUrl( $to ),
				'mode'          => $this->isShared( $from, $postId ) ? self::MODE_COPY : self::MODE_MOVE,
				'attachment_id' => $attachmentId,
			);
		}

		return array( 'ops' => $ops, 'warnings' => $warnings );
	}

	/**
	 * Выполняет операции всех заданий партии разом.
	 *
	 * @param array<int, array<string, mixed>> $ops Операции из {@see plan()}
	 *
	 * @throws \RuntimeException Если файл не удалось переместить (уже сделанное откатывается).
	 */
	public function execute( array $ops ): void {
		$done = array();

		try {
			// Копии — первыми: источник ещё на месте.
			foreach ( $ops as $op ) {
				if ( self::MODE_COPY === $op['mode'] ) {
					if ( ! copy( $op['from'], $op['to'] ) ) {
						throw new \RuntimeException( 'Не удалось скопировать файл ' . basename( $op['from'] ) );
					}
					$done[] = array( 'copied', $op );
				}
			}

			$temps = array();
			foreach ( $ops as $i => $op ) {
				if ( self::MODE_MOVE === $op['mode'] ) {
					$temp = dirname( $op['from'] ) . '/.fsbulk-' . wp_generate_password( 8, false ) . '-' . basename( $op['from'] );
					if ( ! rename( $op['from'], $temp ) ) {
						throw new \RuntimeException( 'Не удалось переместить файл ' . basename( $op['from'] ) );
					}
					$temps[ $i ] = $temp;
					$done[]      = array( 'tempped', $op, $temp );
				}
			}

			foreach ( $temps as $i => $temp ) {
				if ( ! rename( $temp, $ops[ $i ]['to'] ) ) {
					throw new \RuntimeException( 'Не удалось записать файл ' . basename( $ops[ $i ]['to'] ) );
				}
				$done[] = array( 'moved', $ops[ $i ], $temp );
			}
		} catch ( \Throwable $e ) {
			$this->undoPartial( $done );
			throw new \RuntimeException( $e->getMessage(), 0, $e );
		}

		foreach ( $ops as $op ) {
			if ( self::MODE_MOVE === $op['mode'] && (int) $op['attachment_id'] > 0 ) {
				update_attached_file( (int) $op['attachment_id'], $op['to'] );
			}
		}
	}

	/**
	 * Возвращает файлы на прежние места (откат партии): перенесённые — обратно, копии — удаляются.
	 *
	 * @param array<int, array<string, mixed>> $ops Операции, ранее переданные в {@see execute()}
	 */
	public function revert( array $ops ): void {
		foreach ( $ops as $op ) {
			if ( self::MODE_COPY === $op['mode'] && is_file( $op['to'] ) ) {
				wp_delete_file( $op['to'] );
			}
		}

		$temps = array();
		foreach ( $ops as $i => $op ) {
			if ( self::MODE_MOVE === $op['mode'] && is_file( $op['to'] ) ) {
				$temp = dirname( $op['to'] ) . '/.fsbulk-' . wp_generate_password( 8, false ) . '-' . basename( $op['to'] );
				if ( rename( $op['to'], $temp ) ) {
					$temps[ $i ] = $temp;
				}
			}
		}

		foreach ( $temps as $i => $temp ) {
			rename( $temp, $ops[ $i ]['from'] );
			if ( (int) $ops[ $i ]['attachment_id'] > 0 ) {
				update_attached_file( (int) $ops[ $i ]['attachment_id'], $ops[ $i ]['from'] );
			}
		}
	}

	/** Путь файла по ссылке, только внутри каталога загрузок; иначе null. */
	public function urlToPath( string $url ): ?string {
		$uploads = wp_get_upload_dir();
		if ( empty( $uploads['baseurl'] ) || empty( $uploads['basedir'] ) ) {
			return null;
		}

		$base = (string) preg_replace( '#^https?:#i', '', $uploads['baseurl'] );
		$rel  = (string) preg_replace( '#^https?:#i', '', $url );
		if ( ! str_starts_with( $rel, $base . '/' ) || str_contains( $rel, '..' ) ) {
			return null;
		}

		$path = rtrim( $uploads['basedir'], '/' ) . '/' . rawurldecode( ltrim( substr( $rel, strlen( $base ) ), '/' ) );

		return false === strpos( $path, "\0" ) ? $path : null;
	}

	/** Ссылка по пути в каталоге загрузок (схема сайта). */
	private function pathToUrl( string $path ): string {
		$uploads = wp_get_upload_dir();
		$rel     = ltrim( substr( $path, strlen( rtrim( $uploads['basedir'], '/' ) ) ), '/' );

		return rtrim( set_url_scheme( $uploads['baseurl'] ), '/' ) . '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $rel ) ) );
	}

	/** Ссылается ли на файл ещё какое-то задание (кроме этого). */
	private function isShared( string $path, int $postId ): bool {
		global $wpdb;

		$uploads = wp_get_upload_dir();
		$rel     = ltrim( substr( $path, strlen( rtrim( $uploads['basedir'], '/' ) ) ), '/' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id <> %d AND meta_value LIKE %s",
				\Inc\Enums\Wp\PostMetaName::Meta->value,
				$postId,
				'%' . $wpdb->esc_like( $rel ) . '%'
			)
		);

		return $count > 0;
	}

	private function hasThumbnails( int $attachmentId ): bool {
		$meta = wp_get_attachment_metadata( $attachmentId );

		return is_array( $meta ) && ! empty( $meta['sizes'] );
	}

	/** @param array<int, array<int, mixed>> $done */
	private function undoPartial( array $done ): void {
		foreach ( array_reverse( $done ) as $step ) {
			[ $kind, $op ] = $step;
			if ( 'copied' === $kind && is_file( $op['to'] ) ) {
				wp_delete_file( $op['to'] );
			} elseif ( 'moved' === $kind && is_file( $op['to'] ) ) {
				rename( $op['to'], $step[2] );
			}
		}
		foreach ( array_reverse( $done ) as $step ) {
			if ( 'tempped' === $step[0] && isset( $step[2] ) && is_file( $step[2] ) ) {
				rename( $step[2], $step[1]['from'] );
			}
		}
	}
}
