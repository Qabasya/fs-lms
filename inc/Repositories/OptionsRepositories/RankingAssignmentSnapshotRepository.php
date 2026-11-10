<?php

declare( strict_types=1 );

namespace Inc\Repositories\OptionsRepositories;

use Inc\Enums\Settings\OptionName;

/** Не перезаписывает состав и дату первой выдачи работы. */
class RankingAssignmentSnapshotRepository {
	public function forLesson( int $lessonId ): array {
		$all = get_option( OptionName::RankingAssignmentSnapshots->value, array() );
		return is_array( $all ) && isset( $all[ $lessonId ] ) && is_array( $all[ $lessonId ] ) ? $all[ $lessonId ] : array();
	}

	/** Снимок из БД под общей блокировкой, без устаревшего object cache. */
	public function forLessonFresh( int $lessonId ): array {
		$wpdb = $GLOBALS['wpdb'];
		$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, OptionName::RankingAssignmentSnapshots->value ) );
		if ( '' !== $wpdb->last_error ) {
			throw new \RuntimeException( 'Не удалось прочитать снимки работ.' );
		}
		$all = maybe_unserialize( $raw );
		return is_array( $all ) && isset( $all[ $lessonId ] ) && is_array( $all[ $lessonId ] ) ? $all[ $lessonId ] : array();
	}

	public function synchronized( callable $operation ): void {
		$this->synchronizedWrite( $operation, static function (): void {} );
	}

	/** Сначала подтверждает снимок, затем меняет работу под той же блокировкой. */
	public function synchronizedWrite( callable $operation, callable $write ): void {
		$wpdb = $GLOBALS['wpdb'];
		$this->acquireLock();
		try {
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				throw new \RuntimeException( 'Не удалось начать сохранение работ.' );
			}
			try {
				$operation();
				if ( false === $wpdb->query( 'COMMIT' ) ) {
					throw new \RuntimeException( 'Не удалось подтвердить сохранение работ.' );
				}
				wp_cache_delete( OptionName::RankingAssignmentSnapshots->value, 'options' );
				wp_cache_delete( 'alloptions', 'options' );
				wp_cache_delete( 'notoptions', 'options' );
			} catch ( \Throwable $error ) {
				$wpdb->query( 'ROLLBACK' );
				wp_cache_delete( OptionName::RankingAssignmentSnapshots->value, 'options' );
				wp_cache_delete( 'alloptions', 'options' );
				wp_cache_delete( 'notoptions', 'options' );
				throw $error;
			}
			$write();
		} finally {
			$this->releaseLock();
		}
	}

	public function acquireLock(): void {
		$wpdb = $GLOBALS['wpdb'];
		$key = 'fs-lms:ranking-assign:' . substr( hash( 'sha256', (string) $wpdb->options ), 0, 32 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $key ) ) ) {
			throw new \RuntimeException( 'Не удалось заблокировать снимки работ.' );
		}
	}

	public function releaseLock(): void {
		$wpdb = $GLOBALS['wpdb'];
		$key = 'fs-lms:ranking-assign:' . substr( hash( 'sha256', (string) $wpdb->options ), 0, 32 );
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );
	}

	/** Вызывать только внутри synchronized(). */
	public function addMissing( int $lessonId, array $snapshots ): void {
		if ( ! $snapshots ) {
			return;
		}
			$name = OptionName::RankingAssignmentSnapshots->value;
			$wpdb = $GLOBALS['wpdb'];
			$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $name ) );
			if ( '' !== $wpdb->last_error ) {
				throw new \RuntimeException( 'Не удалось прочитать снимки работ.' );
			}
			$all = maybe_unserialize( $raw );
			$all = is_array( $all ) ? $all : array();
			$existing = is_array( $all[ $lessonId ] ?? null ) ? $all[ $lessonId ] : array();
			$merged = $existing + $snapshots;
			foreach ( $snapshots as $id => $snapshot ) {
				if ( ! empty( $existing[ $id ]['pending'] ) && empty( $snapshot['pending'] ) ) {
					$merged[ $id ] = $snapshot;
				} elseif ( ! empty( $existing[ $id ]['pending'] ) && ! empty( $snapshot['publication_unconfirmed'] ) ) {
					$merged[ $id ] = array_merge( $snapshot, $existing[ $id ], array( 'publication_unconfirmed' => true ) );
				}
			}
			if ( $existing !== $merged ) {
				$all[ $lessonId ] = $merged;
				$saved = $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s) ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)', $wpdb->options, $name, maybe_serialize( $all ), 'no' ) );
				if ( false === $saved ) {
					throw new \RuntimeException( 'Не удалось сохранить снимки работ.' );
				}
			}
	}
}
