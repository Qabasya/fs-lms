<?php
/**
 * Перенос связей существующих аккаунтов в общую промо-модель.
 *
 * @package Inc\Modules\Masterclass\Repositories
 */

declare( strict_types=1 );

namespace Inc\Modules\Masterclass\Repositories;

use Inc\Enums\Settings\TableName;
use Inc\Modules\Masterclass\Enums\AccountDeleteReason;
use Inc\Modules\Masterclass\Enums\MasterclassAccountState;
use Inc\Modules\Masterclass\Schema\MasterclassSchema;
use Inc\Repositories\WPDBRepositories\AbstractExamRepository;

/** Повторяемый backfill не меняет исходные строки и индивидуальные назначения. */
class PromoBackfillRepository extends AbstractExamRepository {

	private const SOURCE = 'masterclass';

	/**
	 * Обновляет событие и унаследованные сроки одним оператором, включая внешнюю транзакцию.
	 *
	 * @param array<string, mixed> $data Изменяемые поля события.
	 */
	public function updateEventTogether( int $id, array $data ): void {
		$this->assertTransactionalTables();
		$freeze = (string) $data['freeze_at'];
		$set = array();
		$args = array( MasterclassSchema::masterclasses(), TableName::PromoEvents->prefixed(), self::SOURCE, TableName::PromoEventLinks->prefixed(), TableName::PromoAccounts->prefixed() );
		foreach ( $data as $column => $value ) {
			$set[] = null === $value ? 'm.%i = NULL' : 'm.%i = %s';
			$args[] = $column;
			if ( null !== $value ) {
				$args[] = $value;
			}
		}
		$set[] = 'l.expires_at = CASE WHEN l.expires_at = l.source_expires_at THEN %s ELSE l.expires_at END';
		$args[] = $freeze;
		$set[] = 'l.source_expires_at = %s';
		$args[] = $freeze;
		$set[] = 'p.expires_at = CASE WHEN p.origin_type = %s AND p.origin_id = l.participant_id AND p.expires_at = p.source_expires_at THEN %s ELSE p.expires_at END';
		$args[] = self::SOURCE;
		$args[] = $freeze;
		$set[] = 'p.source_expires_at = CASE WHEN p.origin_type = %s AND p.origin_id = l.participant_id THEN %s ELSE p.source_expires_at END';
		$args[] = self::SOURCE;
		$args[] = $freeze;
		$args[] = $id;
		$sql = 'UPDATE %i m LEFT JOIN %i e ON e.source_type = %s AND e.source_id = m.id LEFT JOIN %i l ON l.event_id = e.id LEFT JOIN %i p ON p.id = l.account_id SET ' . implode( ', ', $set ) . ' WHERE m.id = %d';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL состоит только из постоянных фрагментов и плейсхолдеров.
		$this->write( $this->wpdb->prepare( $sql, $args ) );
	}

	/** Одна SQL-операция допустима и внутри транзакции удаления пользователя. */
	public function markDeletedTogether( int $id, AccountDeleteReason $reason, string $nowUtc ): bool {
		$this->assertTransactionalTables();
		return $this->write(
			$this->wpdb->prepare(
				'UPDATE %i a LEFT JOIN %i e ON e.source_type = %s AND e.source_id = a.masterclass_id LEFT JOIN %i l ON l.event_id = e.id AND l.participant_id = a.id SET a.deleted_at = %s, a.delete_reason = %s, a.updated_at = %s, l.revoked_at = COALESCE(l.revoked_at, %s) WHERE a.id = %d AND a.deleted_at IS NULL',
				MasterclassSchema::accounts(),
				TableName::PromoEvents->prefixed(),
				self::SOURCE,
				TableName::PromoEventLinks->prefixed(),
				$nowUtc,
				$reason->value,
				$nowUtc,
				$nowUtc,
				$id
			)
		) > 0;
	}

	/** Снимает учебную запись и отзывает связанную промо-связь одним оператором. */
	public function detachTogether( int $id, string $nowUtc, bool $enrolled = false ): void {
		$this->assertTransactionalTables();
		if ( $enrolled ) {
			$this->write(
				$this->wpdb->prepare(
					'UPDATE %i a LEFT JOIN %i e ON e.source_type = %s AND e.source_id = a.masterclass_id LEFT JOIN %i l ON l.event_id = e.id AND l.participant_id = a.id SET a.state = %s, a.updated_at = %s, l.revoked_at = COALESCE(l.revoked_at, %s) WHERE a.id = %d',
					MasterclassSchema::accounts(),
					TableName::PromoEvents->prefixed(),
					self::SOURCE,
					TableName::PromoEventLinks->prefixed(),
					MasterclassAccountState::Enrolled->value,
					$nowUtc,
					$nowUtc,
					$id
				)
			);
			return;
		}
		$this->write(
			$this->wpdb->prepare(
				'UPDATE %i a LEFT JOIN %i e ON e.source_type = %s AND e.source_id = a.masterclass_id LEFT JOIN %i l ON l.event_id = e.id AND l.participant_id = a.id SET a.student_record_id = NULL, a.updated_at = %s, l.revoked_at = COALESCE(l.revoked_at, %s) WHERE a.id = %d',
				MasterclassSchema::accounts(),
				TableName::PromoEvents->prefixed(),
				self::SOURCE,
				TableName::PromoEventLinks->prefixed(),
				$nowUtc,
				$nowUtc,
				$id
			)
		);
	}

	/**
	 * Атомарно прикрепляет учётку и переносит её связь.
	 *
	 * @throws \Throwable Если запись не удалась.
	 */
	public function attachAtomic( int $id, int $userId, int $personId, int $recordId, string $nowUtc ): void {
		$this->assertTransactionalTables();
		$this->write( 'START TRANSACTION' );
		try {
			$row = $this->readRow( $this->wpdb->prepare( 'SELECT user_id, person_id, student_record_id, deleted_at FROM %i WHERE id = %d FOR UPDATE', MasterclassSchema::accounts(), $id ) );
			if ( null === $row || null !== $row['deleted_at'] || $userId <= 0 || $personId <= 0 || $recordId <= 0 ) {
				throw new \RuntimeException( 'Нельзя привязать учётку к отсутствующему или удалённому участнику.' );
			}
			foreach ( array(
				'user_id'           => $userId,
				'person_id'         => $personId,
				'student_record_id' => $recordId,
			) as $column => $value ) {
				if ( null !== $row[ $column ] && (int) $row[ $column ] !== $value ) {
					throw new \RuntimeException( 'Участник уже связан с другой учёткой или записью.' );
				}
			}
			$this->write(
				$this->wpdb->prepare(
					'UPDATE %i SET user_id = %d, person_id = %d, student_record_id = %d, updated_at = %s WHERE id = %d',
					MasterclassSchema::accounts(),
					$userId,
					$personId,
					$recordId,
					$nowUtc,
					$id
				)
			);
			$this->copyWithinTransaction( $id );
			$this->write( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$this->write( 'ROLLBACK' );
			throw $e;
		}
	}

	/**
	 * Атомарно помечает источник удалённым и отзывает его связь.
	 *
	 * @throws \Throwable Если запись не удалась.
	 */
	public function deleteAtomic( int $id, AccountDeleteReason $reason, string $nowUtc ): bool {
		$this->assertTransactionalTables();
		$this->write( 'START TRANSACTION' );
		try {
			$deleted = 1 === $this->write(
				$this->wpdb->prepare(
					'UPDATE %i SET deleted_at = %s, delete_reason = %s, updated_at = %s WHERE id = %d AND deleted_at IS NULL',
					MasterclassSchema::accounts(),
					$nowUtc,
					$reason->value,
					$nowUtc,
					$id
				)
			);
			$this->copyWithinTransaction( $id );
			$this->write( 'COMMIT' );
			return $deleted;
		} catch ( \Throwable $e ) {
			$this->write( 'ROLLBACK' );
			throw $e;
		}
	}

	/** Возвращает последний обработанный номер, либо входной курсор при пустой выборке. */
	public function copyBatch( int $afterId, int $limit ): int {
		$this->assertTransactionalTables();
		$ids = $this->readInts(
			$this->wpdb->prepare(
				'SELECT id FROM %i WHERE id > %d ORDER BY id LIMIT %d',
				MasterclassSchema::accounts(),
				max( 0, $afterId ),
				max( 1, $limit )
			)
		);
		foreach ( $ids as $id ) {
			$this->copyOne( $id );
			$afterId = $id;
		}
		return $afterId;
	}

	/**
	 * Не начинать запись, если хотя бы одна из участвующих таблиц не поддерживает откат.
	 *
	 * @throws \RuntimeException При неподдерживаемом движке.
	 */
	private function assertTransactionalTables(): void {
		$tables = array(
			MasterclassSchema::accounts(),
			MasterclassSchema::masterclasses(),
			TableName::PromoAccounts->prefixed(),
			TableName::PromoEvents->prefixed(),
			TableName::PromoEventLinks->prefixed(),
		);
		foreach ( $tables as $table ) {
			$row = $this->readRow( $this->wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $this->wpdb->esc_like( $table ) ) );
			if ( null === $row || (string) $row['Name'] !== $table || 'innodb' !== strtolower( (string) $row['Engine'] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- исключение не является HTML.
				throw new \RuntimeException( 'Для переноса промо требуется таблица InnoDB: ' . $table );
			}
		}
	}

	/**
	 * Копирует одну строку атомарно; ошибка оставляет её для повтора.
	 *
	 * @throws \Throwable При ошибке переноса.
	 */
	private function copyOne( int $id ): void {
		$this->write( 'START TRANSACTION' );
		try {
			$this->copyWithinTransaction( $id );
			$this->write( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$this->write( 'ROLLBACK' );
			throw $e;
		}
	}

	/** Копирует запись внутри уже начатой вызывающим транзакции. */
	public function copyWithinTransaction( int $id ): void {
			$row = $this->readRow(
				$this->wpdb->prepare(
					'SELECT a.*, m.freeze_at FROM %i a LEFT JOIN %i m ON m.id = a.masterclass_id WHERE a.id = %d FOR UPDATE',
					MasterclassSchema::accounts(),
					MasterclassSchema::masterclasses(),
					$id
				)
			);
		if ( null !== $row && null !== $row['user_id'] ) {
			$this->copyAttached( $row );
		}
	}

	/**
	 * Переносит привязанный аккаунт.
	 *
	 * @param array<string, mixed> $row Исходная строка под блокировкой.
	 * @throws \RuntimeException При несовместимых исходных данных.
	 */
	private function copyAttached( array $row ): void {
		$userId   = (int) $row['user_id'];
		$personId = (int) $row['person_id'];
		if ( $userId <= 0 || $personId <= 0 || null === $row['freeze_at'] ) {
			throw new \RuntimeException( 'Неполная исходная запись аккаунта: ' . (int) $row['id'] );
		}
		$accountTable = TableName::PromoAccounts->prefixed();
		$eventTable   = TableName::PromoEvents->prefixed();
		$linkTable    = TableName::PromoEventLinks->prefixed();
		// Отвязанный или зачисленный участник больше не принадлежит событию, даже если
		// исходная строка сохранена для воронки. Повторный перенос не оживляет связь.
		$revokedAt = $row['deleted_at'];
		if ( null === $revokedAt && ( null === $row['student_record_id'] || MasterclassAccountState::Enrolled->value === $row['state'] ) ) {
			$revokedAt = $row['updated_at'];
		}
		$this->write( $this->wpdb->prepare(
			'INSERT INTO %i (user_id, person_id, origin_type, origin_id, expires_at, source_expires_at, created_at, updated_at) VALUES (%d, %d, %s, %d, %s, %s, %s, %s) ON DUPLICATE KEY UPDATE id = id',
			$accountTable, $userId, $personId, self::SOURCE, (int) $row['id'], $row['freeze_at'], $row['freeze_at'], $row['created_at'], $row['updated_at']
		) );
		$account = $this->readRow( $this->wpdb->prepare( 'SELECT id, person_id FROM %i WHERE user_id = %d FOR UPDATE', $accountTable, $userId ) );
		if ( null === $account || (int) $account['person_id'] !== $personId ) {
			throw new \RuntimeException( 'Учётка промо связана с другим физлицом.' );
		}
		$accountId = (int) $account['id'];
			$this->write(
				$this->wpdb->prepare(
					'UPDATE %i SET expires_at = CASE WHEN expires_at = source_expires_at THEN %s ELSE expires_at END, source_expires_at = %s WHERE id = %d AND origin_type = %s AND origin_id = %d',
					$accountTable,
					$row['freeze_at'],
					$row['freeze_at'],
					$accountId,
					self::SOURCE,
					(int) $row['id']
				)
			);
		$this->write( $this->wpdb->prepare(
			'INSERT INTO %i (source_type, source_id, created_at, updated_at) VALUES (%s, %d, %s, %s) ON DUPLICATE KEY UPDATE id = id',
			$eventTable,
			self::SOURCE,
			(int) $row['masterclass_id'],
			$row['created_at'],
			$row['updated_at']
		) );
		$event = $this->readRow( $this->wpdb->prepare( 'SELECT id FROM %i WHERE source_type = %s AND source_id = %d FOR UPDATE', $eventTable, self::SOURCE, (int) $row['masterclass_id'] ) );
		if ( null === $event ) {
			throw new \RuntimeException( 'Не удалось получить событие промо.' );
		}
		$eventId = (int) $event['id'];
		$link    = $this->readRow( $this->wpdb->prepare( 'SELECT account_id FROM %i WHERE event_id = %d AND participant_id = %d FOR UPDATE', $linkTable, $eventId, (int) $row['id'] ) );
		if ( null !== $link && (int) $link['account_id'] !== $accountId ) {
			throw new \RuntimeException( 'Участие уже связано с другой учёткой.' );
		}
		if ( null === $link ) {
			$this->insertRow(
				$linkTable,
				array(
					'account_id'        => $accountId,
					'event_id'          => $eventId,
					'participant_id'    => (int) $row['id'],
					'expires_at'        => $row['freeze_at'],
					'source_expires_at' => $row['freeze_at'],
					'revoked_at'        => $revokedAt,
					'created_at'        => $row['created_at'],
					'updated_at'        => $row['updated_at'],
				)
			);
		} else {
			$this->write(
				$this->wpdb->prepare(
					'UPDATE %i SET expires_at = CASE WHEN expires_at = source_expires_at THEN %s ELSE expires_at END, source_expires_at = %s WHERE event_id = %d AND participant_id = %d',
					$linkTable,
					$row['freeze_at'],
					$row['freeze_at'],
					$eventId,
					(int) $row['id']
				)
			);
			if ( null !== $revokedAt ) {
				$this->write( $this->wpdb->prepare( 'UPDATE %i SET revoked_at = COALESCE(revoked_at, %s) WHERE event_id = %d AND participant_id = %d', $linkTable, $revokedAt, $eventId, (int) $row['id'] ) );
			}
		}
	}
}
