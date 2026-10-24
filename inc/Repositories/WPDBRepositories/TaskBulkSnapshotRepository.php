<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\Enums\Settings\TableName;

/**
 * Class TaskBulkSnapshotRepository
 *
 * Снимки массовой правки заданий (`fs_lms_task_bulk_snapshots`): по снимку правку откатывают
 * и выгружают в CSV. Содержимое снимка (`items`) — JSON списка заданий партии.
 *
 * @package Inc\Repositories\WPDBRepositories
 */
class TaskBulkSnapshotRepository {

	public const string STATUS_APPLYING    = 'applying';
	public const string STATUS_APPLIED     = 'applied';
	public const string STATUS_ROLLED_BACK = 'rolled_back';

	/** Сколько снимков предмета хранится; более старые удаляются при создании нового. */
	public const int KEEP = 30;

	private \wpdb  $wpdb;
	private string $table;

	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb  = $wpdb ?? $GLOBALS['wpdb'];
		$this->table = TableName::TaskBulkSnapshots->prefixed();
	}

	/**
	 * Создаёт снимок и возвращает его номер.
	 *
	 * @param array<int, array<string, mixed>> $items
	 */
	public function create( string $subjectKey, int $actorUserId, array $items, string $createdAt ): int {
		$this->wpdb->insert(
			$this->table,
			array(
				'subject_key'   => $subjectKey,
				'status'        => self::STATUS_APPLYING,
				'actor_user_id' => $actorUserId > 0 ? $actorUserId : null,
				'items_count'   => count( $items ),
				'items'         => (string) wp_json_encode( $items, JSON_UNESCAPED_UNICODE ),
				'created_at'    => $createdAt,
			)
		);

		$id = (int) $this->wpdb->insert_id;
		$this->prune( $subjectKey );

		return $id;
	}

	/**
	 * Снимок целиком (с заданиями); null — нет такого.
	 *
	 * @return array{id: int, subject_key: string, status: string, actor_user_id: int, items_count: int, created_at: string, rolled_back_at: ?string, items: array<int, array<string, mixed>>}|null
	 */
	public function find( int $id ): ?array {
		$row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}

		$items = json_decode( (string) $row['items'], true );

		return $this->shape( $row ) + array( 'items' => is_array( $items ) ? $items : array() );
	}

	/**
	 * Снимки предмета без заданий, новые сверху.
	 *
	 * @return array<int, array{id: int, subject_key: string, status: string, actor_user_id: int, items_count: int, created_at: string, rolled_back_at: ?string}>
	 */
	public function listBySubject( string $subjectKey ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT id, subject_key, status, actor_user_id, items_count, created_at, rolled_back_at FROM {$this->table} WHERE subject_key = %s AND status <> %s ORDER BY id DESC LIMIT %d",
				$subjectKey,
				self::STATUS_APPLYING,
				self::KEEP
			),
			ARRAY_A
		);

		return array_map( fn( array $row ): array => $this->shape( $row ), is_array( $rows ) ? $rows : array() );
	}

	public function markApplied( int $id ): void {
		$this->wpdb->update( $this->table, array( 'status' => self::STATUS_APPLIED ), array( 'id' => $id ) );
	}

	public function markRolledBack( int $id, string $at ): void {
		$this->wpdb->update( $this->table, array( 'status' => self::STATUS_ROLLED_BACK, 'rolled_back_at' => $at ), array( 'id' => $id ) );
	}

	public function delete( int $id ): void {
		$this->wpdb->delete( $this->table, array( 'id' => $id ) );
	}

	/** Оставляет последние {@see self::KEEP} снимков предмета. */
	private function prune( string $subjectKey ): void {
		$this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM {$this->table} WHERE subject_key = %s AND id NOT IN (
					SELECT id FROM (SELECT id FROM {$this->table} WHERE subject_key = %s ORDER BY id DESC LIMIT %d) AS keep_rows
				)",
				$subjectKey,
				$subjectKey,
				self::KEEP
			)
		);
	}

	/**
	 * @param array<string, mixed> $row
	 *
	 * @return array{id: int, subject_key: string, status: string, actor_user_id: int, items_count: int, created_at: string, rolled_back_at: ?string}
	 */
	private function shape( array $row ): array {
		return array(
			'id'             => (int) $row['id'],
			'subject_key'    => (string) $row['subject_key'],
			'status'         => (string) $row['status'],
			'actor_user_id'  => (int) ( $row['actor_user_id'] ?? 0 ),
			'items_count'    => (int) $row['items_count'],
			'created_at'     => (string) $row['created_at'],
			'rolled_back_at' => isset( $row['rolled_back_at'] ) ? (string) $row['rolled_back_at'] : null,
		);
	}
}
