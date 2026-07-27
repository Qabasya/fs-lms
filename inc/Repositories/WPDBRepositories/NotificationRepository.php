<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\DTO\Profile\NotificationDTO;
use Inc\Enums\Settings\TableName;

/**
 * Class NotificationRepository
 *
 * Доступ к таблице fs_lms_notifications. Идемпотентная вставка (`INSERT IGNORE`
 * по `UNIQUE(recipient_user_id, dedupe_key)`) — повторный cron-тик или
 * перепривязка одной и той же сущности не плодят дубли уведомлений.
 *
 * @package Inc\Repositories\WPDBRepositories
 */
class NotificationRepository {

	private \wpdb  $wpdb;
	private string $table;

	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb  = $wpdb ?? $GLOBALS['wpdb'];
		$this->table = TableName::Notifications->prefixed();
	}

	/**
	 * Идемпотентная вставка. Возвращает true, если строка реально вставлена
	 * (false — уже существовала такая (recipient, dedupe_key) пара).
	 *
	 * @param array<string,mixed> $payload
	 */
	public function insertIgnore(
		int     $recipientUserId,
		string  $type,
		string  $dedupeKey,
		array   $payload = array(),
		string  $url = '',
		?int    $groupId = null,
		?string $entityType = null,
		?int    $entityId = null
	): bool {
		// group_id/entity_type/entity_id — колонки NULLABLE; %d/%s из prepare() превращают
		// PHP null в 0/'', поэтому для null-значений подставляем литерал NULL, а не плейсхолдер
		// (значения выбираются здесь же по === null, не пользовательским вводом).
		$groupSql  = null === $groupId ? 'NULL' : '%d';
		$entTypeSql = null === $entityType ? 'NULL' : '%s';
		$entIdSql  = null === $entityId ? 'NULL' : '%d';

		$values = array( $this->table, $recipientUserId, $type );
		if ( null !== $groupId ) {
			$values[] = $groupId;
		}
		if ( null !== $entityType ) {
			$values[] = $entityType;
		}
		if ( null !== $entityId ) {
			$values[] = $entityId;
		}
		$values[] = wp_json_encode( $payload );
		$values[] = $url;
		$values[] = $dedupeKey;
		$values[] = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $groupSql/$entTypeSql/$entIdSql — 'NULL' либо плейсхолдер, не пользовательский ввод
		$sql = $this->wpdb->prepare(
			"INSERT IGNORE INTO %i
			 (recipient_user_id, type, group_id, entity_type, entity_id, payload, url, dedupe_key, created_at)
			 VALUES (%d, %s, $groupSql, $entTypeSql, $entIdSql, %s, %s, %s, %s)",
			$values
		);

		return (int) $this->wpdb->query( $sql ) > 0;
	}

	/** @return NotificationDTO[] Последние N уведомлений получателя, свежие сверху. */
	public function listRecent( int $userId, int $limit = 30 ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE recipient_user_id = %d ORDER BY created_at DESC LIMIT %d',
				$this->table,
				$userId,
				$limit
			),
			ARRAY_A
		);

		return array_map( array( NotificationDTO::class, 'fromArray' ), $rows ?: array() );
	}

	public function unseenCount( int $userId ): int {
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE recipient_user_id = %d AND seen_at IS NULL',
				$this->table,
				$userId
			)
		);
	}

	public function markAllSeen( int $userId ): void {
		$this->wpdb->query(
			$this->wpdb->prepare(
				'UPDATE %i SET seen_at = %s WHERE recipient_user_id = %d AND seen_at IS NULL',
				$this->table,
				current_time( 'mysql', true ),
				$userId
			)
		);
	}

	/** `WHERE recipient_user_id = %d` — чужое уведомление недостижимо по чужому id. */
	public function markRead( int $userId, int $id ): void {
		$this->wpdb->query(
			$this->wpdb->prepare(
				'UPDATE %i SET read_at = %s WHERE id = %d AND recipient_user_id = %d AND read_at IS NULL',
				$this->table,
				current_time( 'mysql', true ),
				$id,
				$userId
			)
		);
	}

	public function markAllRead( int $userId ): void {
		$this->wpdb->query(
			$this->wpdb->prepare(
				'UPDATE %i SET read_at = %s WHERE recipient_user_id = %d AND read_at IS NULL',
				$this->table,
				current_time( 'mysql', true ),
				$userId
			)
		);
	}

	/**
	 * Отзывает (удаляет) непрочитанные уведомления по dedupe-ключу — например,
	 * при исправлении ошибочной отметки «отсутствовал» на «присутствовал».
	 *
	 * @param int[] $userIds
	 */
	public function deleteByDedupe( array $userIds, string $dedupeKey ): void {
		if ( empty( $userIds ) ) {
			return;
		}
		$placeholders = implode( ', ', array_fill( 0, count( $userIds ), '%d' ) );
		$values       = array_merge( array( $this->table ), $userIds, array( $dedupeKey ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM %i WHERE recipient_user_id IN ($placeholders) AND dedupe_key = %s",
				$values
			)
		);
	}

	/** Прочитанные старше $readOlderDays дней и любые старше $allOlderDays — удаляются. */
	public function purge( int $readOlderDays = 30, int $allOlderDays = 90 ): void {
		$this->wpdb->query(
			$this->wpdb->prepare(
				'DELETE FROM %i WHERE
				 (read_at IS NOT NULL AND read_at < DATE_SUB(NOW(), INTERVAL %d DAY))
				 OR created_at < DATE_SUB(NOW(), INTERVAL %d DAY)',
				$this->table,
				$readOlderDays,
				$allOlderDays
			)
		);
	}
}
