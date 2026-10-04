<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\DTO\Exam\ExamGuestApplicationDTO;
use Inc\Enums\Settings\TableName;

/**
 * Заявки гостей: бронь места, оплата, запись.
 *
 * Бронь держит место в `exam_sessions.occupied_count`, пока `is_held = 1`. Флаг переключается один раз
 * ({@see releaseHeldFlag()}): это и есть гарантия, что место освобождается ровно один раз при любом числе параллельных вызовов.
 */
class ExamGuestApplicationRepository extends AbstractExamRepository {

	private string $table;

	public function __construct( ?\wpdb $wpdb = null ) {
		parent::__construct( $wpdb );
		$this->table = TableName::ExamGuestApplications->prefixed();
	}

	/**
	 * @param array<string, mixed> $data
	 *
	 * @throws DuplicateKeyException Личность уже имеет активную заявку (`identity_active`) или ключ источника повторён (`source_request`).
	 */
	public function insert( array $data ): int {
		return $this->insertRow( $this->table, $data );
	}

	public function find( int $id ): ?ExamGuestApplicationDTO {
		$row = $this->readRow( $this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table, $id ) );
		return null !== $row ? ExamGuestApplicationDTO::fromArray( $row ) : null;
	}

	/** Блокирующее чтение заявки: вызывать только внутри транзакции. */
	public function findForUpdate( int $id ): ?ExamGuestApplicationDTO {
		$row = $this->readRow( $this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d FOR UPDATE', $this->table, $id ) );
		return null !== $row ? ExamGuestApplicationDTO::fromArray( $row ) : null;
	}

	/** Заявка по ключу идемпотентности внутри источника. */
	public function findBySourceAndRequestKey( int $sourceId, string $requestKey ): ?ExamGuestApplicationDTO {
		$row = $this->readRow( $this->wpdb->prepare( 'SELECT * FROM %i WHERE source_id = %d AND request_key = %s', $this->table, $sourceId, $requestKey ) );
		return null !== $row ? ExamGuestApplicationDTO::fromArray( $row ) : null;
	}

	/** Есть ли у личности (хеш «ФИО + телефон») действующая заявка в проведении: индекс `identity_active` допускает одну. */
	public function hasActiveByIdentity( int $eventId, string $identityHash, int $excludeId = 0 ): bool {
		return $this->readInt( $this->wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE event_id = %d AND identity_hash = %s AND active_slot = 1 AND id <> %d',
			$this->table,
			$eventId,
			$identityHash,
			$excludeId
		) ) > 0;
	}

	/**
	 * Версионированное обновление заявки (вызывающий держит блокировку строки и передаёт версию, прочитанную под ней).
	 *
	 * @param array<string, scalar|null> $data
	 *
	 * @return bool false — версия устарела.
	 */
	public function update( int $id, array $data, int $expectedVersion ): bool {
		return $this->updateVersioned( $this->table, $id, $data, $expectedVersion );
	}

	/**
	 * Снимает флаг брони одним условным запросом: выигрывает ровно один из параллельных вызовов.
	 *
	 * @return bool true — флаг снят этим вызовом (место освобождает именно он).
	 */
	public function releaseHeldFlag( int $id ): bool {
		return 1 === $this->write( $this->wpdb->prepare( 'UPDATE %i SET is_held = 0 WHERE id = %d AND is_held = 1', $this->table, $id ) );
	}

	/**
	 * @return int[] ID заявок с истёкшей бронью, давно истёкшие первыми.
	 */
	public function listExpiredHeldIds( string $nowUtc, int $limit = 100 ): array {
		return $this->readInts( $this->wpdb->prepare(
			'SELECT id FROM %i WHERE is_held = 1 AND hold_expires_at <= %s ORDER BY hold_expires_at ASC, id ASC LIMIT %d',
			$this->table,
			$nowUtc,
			$limit
		) );
	}

	/** @return int[] ID заявок сеанса с истёкшей бронью. */
	public function listExpiredHeldIdsBySession( int $sessionId, string $nowUtc ): array {
		return $this->readInts( $this->wpdb->prepare(
			'SELECT id FROM %i WHERE session_id = %d AND is_held = 1 AND hold_expires_at <= %s ORDER BY id ASC',
			$this->table,
			$sessionId,
			$nowUtc
		) );
	}

	/** Сколько мест сеанса сейчас удерживается бронями гостей. */
	public function countHeldBySession( int $sessionId ): int {
		return $this->readInt( $this->wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE session_id = %d AND is_held = 1', $this->table, $sessionId ) );
	}

	/** Сколько броней держит один источник (лимит источника — этап 11a). */
	public function countHeldBySource( int $sourceId ): int {
		return $this->readInt( $this->wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE source_id = %d AND is_held = 1', $this->table, $sourceId ) );
	}

	/** Сколько броней держит один адрес (лимит по IP — этап 11a); хеш адреса, не сам адрес. */
	public function countHeldByIp( string $ipHash ): int {
		return $this->readInt( $this->wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ip_hash = %s AND is_held = 1', $this->table, $ipHash ) );
	}
}
