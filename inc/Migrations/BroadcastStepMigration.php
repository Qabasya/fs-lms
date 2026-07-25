<?php

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Enums\Wp\PostMetaName;

/**
 * Class BroadcastStepMigration
 *
 * Одноразовая data-миграция Этапа 1: `video`-шаг урока с `payload.recording_slot`
 * → тип `broadcast`, `payload = {title, stream_url: ''}`.
 *
 * ### Почему это отдельный класс, а не часть `Migration_1_0_0::up()`
 *
 * `MigrationRunner::run()` вызывается ТОЛЬКО из `register_activation_hook`
 * ({@see \Inc\Core\Activate}), а его гейт `version_compare('1.0.0', $current, '>')`
 * на установках с уже проставленным `fs_lms_schema_version = 1.0.0` даёт false —
 * `up()` там не запустится никогда. А это ровно те инсталляции, где и лежат
 * `recording_slot`-данные. Плюс на хуке активации CPT `{key}_lessons` ещё не
 * зарегистрированы, поэтому `get_posts`/`WP_Query` по ним молча теряет часть постов.
 *
 * Поэтому миграция:
 *  - **version-gated собственной опцией** `fs_lms_broadcast_migration` (дешёвый
 *    option-read; при совпадении версии — мгновенный no-op). Паттерн — `VideoSchema`/`AdSchema`;
 *  - **вызывается на обычной загрузке** из {@see \Inc\Init::run()}, а не только при активации;
 *  - **читает кандидатов прямым `$wpdb`** по `wp_postmeta` (CPT-агностично — не зависит
 *    от того, зарегистрированы ли уже типы записей).
 *
 * `key` шага НЕ меняется — на него завязаны прогресс (`lesson_progress`) и
 * `step_settings_overrides`. Идемпотентна: после переноса тип уже не `video`,
 * повторный проход подходящих шагов не находит.
 *
 * @package Inc\Migrations
 */
class BroadcastStepMigration {

	/** Опция-гейт (наличие = миграция выполнена в этой версии). */
	private const VERSION_OPTION = 'fs_lms_broadcast_migration';

	/** Версия миграции. */
	private const VERSION = '1';

	/**
	 * Выполняет миграцию один раз (version-gated). Вызывать на обычной загрузке.
	 *
	 * @return void
	 */
	public function ensure(): void {
		if ( self::VERSION === get_option( self::VERSION_OPTION ) ) {
			return;
		}

		$this->migrate( $this->candidatePostIds() );

		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	/**
	 * Переносит recording_slot-шаги в переданных постах.
	 *
	 * Выделен из {@see ensure()} для тестируемости: поиск кандидатов — тонкая
	 * SQL-обёртка ({@see candidatePostIds}), а логика трансформации проверяется
	 * прогоном по явному списку id.
	 *
	 * @param int[] $postIds ID постов-уроков (включая форки групп, любой статус)
	 *
	 * @return void
	 */
	public function migrate( array $postIds ): void {
		foreach ( $postIds as $postId ) {
			$this->migratePost( (int) $postId );
		}
	}

	/**
	 * Ищет посты, в мете которых встречается `recording_slot`.
	 *
	 * Прямой запрос по `wp_postmeta` — не зависит от регистрации CPT и статуса
	 * поста (в отличие от `get_posts`).
	 *
	 * @return int[]
	 */
	protected function candidatePostIds(): array {
		global $wpdb;

		$like = '%' . $wpdb->esc_like( 'recording_slot' ) . '%';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s",
				PostMetaName::Meta->value,
				$like
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Переносит recording_slot-шаги одного урока. No-op, если у поста нет
	 * `fs_lms_meta.steps` или подходящих шагов не нашлось.
	 *
	 * @param int $postId ID поста-урока
	 *
	 * @return void
	 */
	private function migratePost( int $postId ): void {
		$meta = get_post_meta( $postId, PostMetaName::Meta->value, true );
		if ( ! is_array( $meta ) || ! is_array( $meta['steps'] ?? null ) ) {
			return;
		}

		$changed = false;
		foreach ( $meta['steps'] as &$step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}

			$type    = (string) ( $step['type'] ?? '' );
			$payload = is_array( $step['payload'] ?? null ) ? $step['payload'] : array();

			if ( 'video' === $type && ! empty( $payload['recording_slot'] ) ) {
				$step['type']    = 'broadcast';
				$step['payload'] = array(
					'title'      => (string) ( $payload['title'] ?? '' ),
					'stream_url' => '',
				);
				$changed = true;
			}
		}
		unset( $step );

		if ( $changed ) {
			update_post_meta( $postId, PostMetaName::Meta->value, $meta );
		}
	}
}
