<?php
/**
 * Read-only video inventory for storage migration.
 *
 * @package FS_LMS
 */

declare(strict_types=1);

namespace Inc\Modules\VideoLibrary\Repositories;

use Inc\Enums\Settings\TableName;
use Inc\Modules\VideoLibrary\Schema\VideoSchema;
use RuntimeException;

/** Reads identifiers and storage pointers, never credentials or video payloads. */
class VideoInventoryRepository {
	/**
	 * WordPress database connection.
	 *
	 * @var \wpdb
	 */
	private readonly \wpdb $wpdb;

	/** Receives the WordPress database connection. */
	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
	}

	/**
	 * Export registry metadata and all nonempty lesson recording pointers.
	 *
	 * @return array{schema:int,recordings:array,lessons:array}
	 * @throws RuntimeException If either inventory query fails.
	 */
	public function snapshot(): array {
		$recordingsSql = $this->wpdb->prepare(
			'SELECT id, s3_bucket, s3_key, manifest_key, sha256, size_bytes, group_slug, group_id, group_lesson_id, status, recorded_at FROM %i ORDER BY id',
			VideoSchema::table()
		);
		$recordings    = $this->wpdb->get_results( $recordingsSql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query prepared above with %i table placeholder.
		if ( '' !== $this->wpdb->last_error ) {
			throw new RuntimeException( 'Не удалось прочитать видеореестр.' );
		}
		$lessonsSql = $this->wpdb->prepare(
			"SELECT id, group_id, recording_url, status FROM %i WHERE recording_url IS NOT NULL AND recording_url <> '' ORDER BY id",
			TableName::GroupLessons->prefixed()
		);
		$lessons    = $this->wpdb->get_results( $lessonsSql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query prepared above with %i table placeholder.
		if ( '' !== $this->wpdb->last_error ) {
			throw new RuntimeException( 'Не удалось прочитать указатели записей занятий.' );
		}
		return array(
			'schema'     => 1,
			'recordings' => $recordings ?? array(),
			'lessons'    => $lessons ?? array(),
		);
	}
}
