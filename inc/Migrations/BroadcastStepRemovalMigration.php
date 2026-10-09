<?php

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Enums\Wp\PostMetaName;

/**
 * Class BroadcastStepRemovalMigration
 *
 * Разовое удаление шагов «Трансляция» из уроков.
 *
 * @package Inc\Migrations
 *
 * Шаг больше не хранится в уроке: плеер сам добавляет первым шагом занятия группы
 * «Трансляцию» (ссылка берётся из `groups.broadcast_url`) и «Запись занятия» после
 * занятия. Прежние шаги вместе с их `stream_url` удаляются безвозвратно — ссылки
 * владелец вносит в группах заново.
 *
 * Правятся ДАННЫЕ (`fs_lms_meta` уроков, включая форки групп), поэтому миграция
 * самодостаточна и version-gated собственной опцией, как {@see TaskFileSchemeMigration}:
 * прямой `$wpdb` по `postmeta` (не зависит от регистрации CPT), при уже выполненной
 * миграции — одно чтение опции.
 */
class BroadcastStepRemovalMigration {

	private const VERSION_OPTION = 'fs_lms_broadcast_steps_removed';
	private const VERSION        = '1';

	public function run(): void {
		if ( get_option( self::VERSION_OPTION ) === self::VERSION ) {
			return;
		}

		$this->removeFromLessons();

		// Версия пишется всегда, даже если править было нечего: иначе запрос повторялся бы на каждой загрузке.
		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	/**
	 * @return int Сколько уроков изменено.
	 */
	public function removeFromLessons(): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.meta_id, m.meta_value
				 FROM {$wpdb->postmeta} m
				 INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
				 WHERE m.meta_key = %s
				   AND p.post_type LIKE %s
				   AND m.meta_value LIKE %s",
				PostMetaName::Meta->value,
				'%\_lessons',
				'%broadcast%'
			),
			ARRAY_A
		);

		$changed = 0;
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$meta = maybe_unserialize( $row['meta_value'] );
			if ( ! is_array( $meta ) || ! is_array( $meta['steps'] ?? null ) ) {
				continue;
			}

			$kept = array_values( array_filter(
				$meta['steps'],
				static fn( $step ): bool => ! ( is_array( $step ) && 'broadcast' === ( $step['type'] ?? '' ) )
			) );

			if ( count( $kept ) === count( $meta['steps'] ) ) {
				continue;
			}

			$meta['steps'] = $kept;
			$wpdb->update( $wpdb->postmeta, array( 'meta_value' => maybe_serialize( $meta ) ), array( 'meta_id' => (int) $row['meta_id'] ) );
			++$changed;
		}
		// phpcs:enable

		return $changed;
	}
}
