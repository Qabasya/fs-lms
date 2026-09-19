<?php

declare( strict_types=1 );

namespace Inc\Services\Task;

use Inc\Enums\Wp\PostMetaName;
use Inc\Shared\PluginLogger;

/**
 * Class TaskFileSchemeService
 *
 * Переводит ссылки на файлы заданий со старой схемы (`http://`) на схему сайта.
 *
 * @package Inc\Services\Task
 *
 * Поле «Файл задания» хранит адрес так, как его вставил автор, и у заданий,
 * заведённых до переезда сайта на HTTPS, там остался `http://`. Для браузера
 * `http://сайт/…` на HTTPS-странице — ЧУЖОЙ origin (схема входит в origin),
 * поэтому атрибут `download` у чипа файла игнорируется: файл открывается во
 * вкладке вместо скачивания, а у части браузеров ещё и блокируется как
 * смешанное содержимое.
 *
 * Вывод это уже чинит на лету ({@see TaskMetaService::getTaskFiles()}), но в
 * мете остаётся старый адрес — он уезжает в экспорт, в пакет переноса предмета
 * и в любое место, которое читает мету напрямую. Этот сервис правит сами
 * данные, разово.
 *
 * Чужие домены не трогаются: там HTTPS может не быть вовсе.
 */
class TaskFileSchemeService {

	/** Ключи меты задания, где лежат прямые ссылки на файлы. */
	private const FILE_KEYS = array( 'file', 'file_primary', 'file_secondary' );

	/**
	 * Правит ссылки во всех заданиях.
	 *
	 * @param bool $dryRun true — только посчитать, ничего не записывая
	 *
	 * @return array{scanned: int, updated: int, links: int} Сколько записей просмотрено,
	 *         сколько обновлено и сколько ссылок переписано
	 */
	public function migrate( bool $dryRun = false ): array {
		$scheme = (string) wp_parse_url( home_url(), PHP_URL_SCHEME );
		$host   = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		// Сайт и так на http — переписывать нечего и не на что.
		if ( 'https' !== $scheme || '' === $host ) {
			return array( 'scanned' => 0, 'updated' => 0, 'links' => 0 );
		}

		$wrongPrefix = 'http://' . $host;
		$rightPrefix = 'https://' . $host;

		$scanned = 0;
		$updated = 0;
		$links   = 0;

		foreach ( $this->postIdsWithOldScheme( $wrongPrefix ) as $postId ) {
			++$scanned;

			$meta = get_post_meta( $postId, PostMetaName::Meta->value, true );

			if ( ! is_array( $meta ) ) {
				continue;
			}

			$changed = 0;

			foreach ( self::FILE_KEYS as $key ) {
				$url = $meta[ $key ] ?? '';

				if ( ! is_string( $url ) || ! str_starts_with( $url, $wrongPrefix ) ) {
					continue;
				}

				$meta[ $key ] = $rightPrefix . substr( $url, strlen( $wrongPrefix ) );
				++$changed;
			}

			if ( 0 === $changed ) {
				continue;
			}

			++$updated;
			$links += $changed;

			if ( ! $dryRun ) {
				update_post_meta( $postId, PostMetaName::Meta->value, $meta );
			}
		}

		if ( $updated > 0 && ! $dryRun ) {
			PluginLogger::warning(
				'TaskFileScheme',
				'Ссылки на файлы заданий переведены на HTTPS',
				array( 'posts' => $updated, 'links' => $links )
			);
		}

		return array( 'scanned' => $scanned, 'updated' => $updated, 'links' => $links );
	}

	/**
	 * ID записей, в мете которых встречается старый префикс.
	 *
	 * Запрос идёт напрямую по `postmeta`, а не через `WP_Query`: мета задания —
	 * сериализованный массив, по нему нельзя отфильтровать `meta_query`, а
	 * перебирать все задания всех предметов ради нескольких строк незачем.
	 * Сама правка дальше идёт через `get_post_meta()`/`update_post_meta()` —
	 * сериализацию руками не трогаем.
	 *
	 * @param string $wrongPrefix Префикс вида `http://example.com`
	 *
	 * @return int[]
	 */
	private function postIdsWithOldScheme( string $wrongPrefix ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s",
				PostMetaName::Meta->value,
				'%' . $wpdb->esc_like( $wrongPrefix ) . '%'
			)
		);

		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}
}
