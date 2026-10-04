<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\DTO\Exam\ExamSessionDTO;
use Inc\Enums\Exam\ExamEventStatus;
use Inc\Enums\Exam\ExamSessionStatus;
use Inc\Enums\Settings\TableName;

class ExamSessionRepository extends AbstractExamRepository {

	private string $table;

	public function __construct( ?\wpdb $wpdb = null ) {
		parent::__construct( $wpdb );
		$this->table = TableName::ExamSessions->prefixed();
	}

	public function insert( array $data ): int {
		return $this->insertRow( $this->table, $data );
	}

	public function find( int $id ): ?ExamSessionDTO {
		$row = $this->readRow( $this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table, $id ) );
		return null !== $row ? ExamSessionDTO::fromArray( $row ) : null;
	}

	/** Блокирующее чтение: вызывать только внутри транзакции. */
	public function findForUpdate( int $id ): ?ExamSessionDTO {
		$row = $this->readRow( $this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d FOR UPDATE', $this->table, $id ) );
		return null !== $row ? ExamSessionDTO::fromArray( $row ) : null;
	}

	/**
	 * Блокирует сеансы в порядке возрастания ID — единый порядок исключает взаимные блокировки
	 * при переносе записи между двумя сеансами.
	 *
	 * @param int[] $ids
	 *
	 * @return ExamSessionDTO[] Ключ — ID сеанса.
	 */
	public function lockInOrder( array $ids ): array {
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		sort( $ids );
		if ( array() === $ids ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = $this->readRows(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- плейсхолдеры собраны из чисел.
			$this->wpdb->prepare( "SELECT * FROM %i WHERE id IN ( {$placeholders} ) ORDER BY id ASC FOR UPDATE", $this->table, ...$ids )
		);

		$result = array();
		foreach ( $rows as $row ) {
			$dto                = ExamSessionDTO::fromArray( $row );
			$result[ $dto->id ] = $dto;
		}
		return $result;
	}

	/** @return ExamSessionDTO[] */
	public function findByEvent( int $eventId ): array {
		$rows = $this->readRows( $this->wpdb->prepare( 'SELECT * FROM %i WHERE event_id = %d ORDER BY scheduled_at ASC', $this->table, $eventId ) );
		return array_map( array( ExamSessionDTO::class, 'fromArray' ), $rows );
	}

	/**
	 * Версионированное обновление сеанса.
	 *
	 * @param array<string, scalar|null> $data
	 *
	 * @return bool false — версия устарела.
	 */
	public function update( int $id, array $data, int $expectedVersion ): bool {
		return $this->updateVersioned( $this->table, $id, $data, $expectedVersion );
	}

	/** Удаляет сеанс. Вызывать только для сеанса без записей (проверяет сервис). */
	public function delete( int $id ): bool {
		return 1 === $this->write( $this->wpdb->prepare( 'DELETE FROM %i WHERE id = %d', $this->table, $id ) );
	}

	/**
	 * Будущие открытые сеансы кабинета — для пересинхронизации вместимости.
	 *
	 * @return ExamSessionDTO[]
	 */
	public function listFutureOpenByRoom( int $roomId, string $nowUtc ): array {
		$rows = $this->readRows( $this->wpdb->prepare(
			'SELECT * FROM %i WHERE room_id = %d AND status = %s AND scheduled_at > %s ORDER BY id ASC',
			$this->table,
			$roomId,
			ExamSessionStatus::Open->value,
			$nowUtc
		) );
		return array_map( array( ExamSessionDTO::class, 'fromArray' ), $rows );
	}

	/** Вместимость сеанса (из кабинета). `version` не меняет: это пересинхронизация, а не правка сеанса. */
	public function setCapacity( int $id, int $capacity ): void {
		$this->write( $this->wpdb->prepare( 'UPDATE %i SET capacity = %d, updated_at = %s WHERE id = %d', $this->table, $capacity, gmdate( 'Y-m-d H:i:s' ), $id ) );
	}

	/**
	 * Отменяет все открытые сеансы проведения (отмена проведения).
	 *
	 * @return int Сколько сеансов отменено.
	 */
	public function cancelOpenByEvent( int $eventId, string $reason, string $nowUtc ): int {
		return $this->write( $this->wpdb->prepare(
			'UPDATE %i SET status = %s, cancel_reason = %s, version = version + 1, updated_at = %s WHERE event_id = %d AND status = %s',
			$this->table,
			ExamSessionStatus::Cancelled->value,
			$reason,
			$nowUtc,
			$eventId,
			ExamSessionStatus::Open->value
		) );
	}

	/**
	 * Сеансы опубликованных проведений в окне дат (UTC) для «Главной» преподавателя: свои — по ответственному, у глобального охвата — все.
	 * Отменённые сеансы и черновики не включаются. Название проведения — в `event_title`.
	 *
	 * @return list<array<string, mixed>> Строки сеанса плюс `event_title`, `event_status`.
	 */
	public function listForTeacherBetween( int $userId, bool $all, string $fromUtc, string $toUtc ): array {
		$events = TableName::ExamEvents->prefixed();
		$sql    = 'SELECT s.*, e.title AS event_title, e.status AS event_status FROM %i s INNER JOIN %i e ON e.id = s.event_id
			 WHERE e.status = %s AND s.status <> %s AND s.scheduled_at >= %s AND s.scheduled_at < %s';
		$args   = array( $this->table, $events, ExamEventStatus::Published->value, ExamSessionStatus::Cancelled->value, $fromUtc, $toUtc );
		if ( ! $all ) {
			$sql   .= ' AND s.responsible_user_id = %d';
			$args[] = $userId;
		}
		$sql .= ' ORDER BY s.scheduled_at ASC, s.id ASC';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- плейсхолдеры собраны выше из фиксированных кусков.
		return $this->readRows( $this->wpdb->prepare( $sql, ...$args ) );
	}

	/**
	 * Занимает место одним условным запросом: вместимость и статус проверяются в самой базе,
	 * поэтому два одновременных запроса не продадут одно место дважды.
	 *
	 * @return bool true — место занято (затронута ровно одна строка).
	 */
	public function occupySeat( int $id ): bool {
		return 1 === $this->write( $this->wpdb->prepare(
			'UPDATE %i SET occupied_count = occupied_count + 1 WHERE id = %d AND status = %s AND occupied_count < capacity',
			$this->table,
			$id,
			ExamSessionStatus::Open->value
		) );
	}

	/** @return bool true — место освобождено; false — занятых мест уже не было. */
	public function releaseSeat( int $id ): bool {
		return 1 === $this->write( $this->wpdb->prepare(
			'UPDATE %i SET occupied_count = occupied_count - 1 WHERE id = %d AND occupied_count > 0',
			$this->table,
			$id
		) );
	}

	/**
	 * Фиксирует момент первой начатой попытки сеанса (после него сеанс «заперт» для правок).
	 * Записывает только один раз.
	 *
	 * @return bool true — отметка поставлена этим вызовом.
	 */
	public function markFirstStarted( int $id, string $atUtc ): bool {
		return 1 === $this->write( $this->wpdb->prepare(
			'UPDATE %i SET first_started_at = %s WHERE id = %d AND first_started_at IS NULL',
			$this->table,
			$atUtc,
			$id
		) );
	}

	/**
	 * Сеансы кабинета, пересекающие окно (UTC), — для предупреждения о позднем старте. Отменённые не считаются.
	 *
	 * @return list<array{title: string, start: string}> Название проведения и начало сеанса (UTC), по возрастанию начала.
	 */
	public function listInWindowByRoom( int $roomId, string $startUtc, string $endUtc, int $excludeSessionId = 0 ): array {
		$rows = $this->readRows( $this->wpdb->prepare(
			'SELECT e.title AS event_title, s.scheduled_at AS session_start FROM %i s INNER JOIN %i e ON e.id = s.event_id
			 WHERE s.room_id = %d AND s.status <> %s AND s.id <> %d AND s.scheduled_at < %s AND s.planned_end_at > %s
			 ORDER BY s.scheduled_at ASC, s.id ASC',
			$this->table,
			TableName::ExamEvents->prefixed(),
			$roomId,
			ExamSessionStatus::Cancelled->value,
			$excludeSessionId,
			$endUtc,
			$startUtc
		) );

		return array_map(
			static fn ( array $row ): array => array( 'title' => (string) $row['event_title'], 'start' => (string) $row['session_start'] ),
			$rows
		);
	}

	/**
	 * Занят ли кабинет в интервале (UTC). Отменённые сеансы не считаются.
	 */
	public function isRoomBusy( int $roomId, string $startUtc, string $endUtc, int $excludeSessionId = 0 ): bool {
		return $this->readInt( $this->wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE room_id = %d AND status <> %s AND id <> %d AND scheduled_at < %s AND planned_end_at > %s',
			$this->table,
			$roomId,
			ExamSessionStatus::Cancelled->value,
			$excludeSessionId,
			$endUtc,
			$startUtc
		) ) > 0;
	}
}
