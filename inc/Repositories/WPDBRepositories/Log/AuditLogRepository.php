<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories\Log;

use Inc\DTO\Log\AuditLogDTO;
use Inc\DTO\Log\AuditLogInputDTO;
use Inc\Enums\TableName;

/**
 * Class AuditLogRepository
 *
 * Репозиторий системного журнала действий для обеспечения прослеживаемости бизнес-логики.
 *
 * @package Inc\Repositories\WPDBRepositories
 *
 * ### Основные обязанности:
 *
 * 1. **Запись событий** — создание записей в журнале аудита (create).
 * 2. **Чтение событий** — поиск записей по ID, по цели (target_type + target_id).
 * 3. **Фильтрация и пагинация** — получение отфильтрованного списка для админ-панели.
 *
 * ### Архитектурная роль:
 *
 * Реализует интерфейс RepositoryInterface для единообразия с другими репозиториями.
 * Использует wpdb для прямых SQL-запросов. Записи аудита являются неизменяемыми
 * (update() выбрасывает исключение, delete() не реализован по архитектурным причинам).
 *
 * ### Примечания:
 *
 * - Журнал аудита служит для отслеживания действий пользователей в системе зачисления.
 * - Записи не должны изменяться или удаляться для обеспечения целостности аудита.
 */
class AuditLogRepository {

	private \wpdb $wpdb;
	private string $table;

	/**
	 * Конструктор репозитория.
	 *
	 * @param \wpdb|null $wpdb Глобальный объект базы данных WordPress
	 */
	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb  = $wpdb ?? $GLOBALS['wpdb'];
		$this->table = TableName::AuditLog->prefixed();
	}

	/**
	 * Находит запись аудита по ID.
	 *
	 * @param int $id ID записи
	 *
	 * @return AuditLogDTO|null
	 */
	public function find( int $id ): ?AuditLogDTO {
		// %i — плейсхолдер для идентификатора таблицы (экранирование)
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', $this->table, $id ),
			ARRAY_A
		);

		return $row ? AuditLogDTO::fromArray( $row ) : null;
	}

	/**
	 * Находит все записи аудита по цели (тип + ID).
	 *
	 * @param string $targetType Тип цели (application, enrollment, person)
	 * @param int    $targetId   ID цели
	 *
	 * @return AuditLogDTO[]
	 */
	public function findByTarget( string $targetType, int $targetId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE target_type = %s AND target_id = %d ORDER BY created_at DESC',
				$this->table,
				$targetType,
				$targetId
			),
			ARRAY_A
		);

		return array_map( fn( array $row ) => AuditLogDTO::fromArray( $row ), $rows ?: array() );
	}

	/**
	 * Возвращает постраничный отфильтрованный список записей аудита.
	 *
	 * @param array $filters Массив фильтров (action, actor_user_id)
	 * @param int   $page    Номер страницы
	 * @param int   $perPage Количество элементов на страницу
	 *
	 * @return AuditLogDTO[]
	 */
	public function list( array $filters, int $page, int $perPage, string $orderby = 'id', string $order = 'DESC' ): array {
		$orderby = in_array( $orderby, array( 'id', 'created_at' ), true ) ? $orderby : 'id';
		$order   = 'ASC' === strtoupper( $order ) ? 'ASC' : 'DESC';

		[ $conditions, $bindings ] = $this->buildConditions( $filters );
		$where      = implode( ' AND ', $conditions );
		$bindings[] = $perPage;
		$bindings[] = ( $page - 1 ) * $perPage;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT * FROM %i WHERE $where ORDER BY $orderby $order LIMIT %d OFFSET %d", $bindings ),
			ARRAY_A
		);

		return array_map( fn( array $row ) => AuditLogDTO::fromArray( $row ), $rows ?: array() );
	}

	public function countFiltered( array $filters ): int {
		[ $conditions, $bindings ] = $this->buildConditions( $filters );
		$where = implode( ' AND ', $conditions );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE $where", $bindings )
		);
	}

	public function listAll( array $filters ): array {
		[ $conditions, $bindings ] = $this->buildConditions( $filters );
		$where = implode( ' AND ', $conditions );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT * FROM %i WHERE $where ORDER BY id DESC", $bindings ),
			ARRAY_A
		);

		return array_map( fn( array $row ) => AuditLogDTO::fromArray( $row ), $rows ?: array() );
	}

	private function buildConditions( array $filters ): array {
		$conditions = array( '1=1' );
		$bindings   = array( $this->table );

		if ( ! empty( $filters['action'] ) ) {
			$conditions[] = 'action = %s';
			$bindings[]   = $filters['action'];
		}
		if ( ! empty( $filters['actor_user_id'] ) ) {
			$conditions[] = 'actor_user_id = %d';
			$bindings[]   = (int) $filters['actor_user_id'];
		}
		if ( isset( $filters['actor_ids'] ) && is_array( $filters['actor_ids'] ) ) {
			if ( empty( $filters['actor_ids'] ) ) {
				$conditions[] = '1=0';
			} else {
				$placeholders = implode( ', ', array_fill( 0, count( $filters['actor_ids'] ), '%d' ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$conditions[] = "actor_user_id IN ($placeholders)";
				foreach ( $filters['actor_ids'] as $uid ) {
					$bindings[] = (int) $uid;
				}
			}
		}
		if ( ! empty( $filters['date_from'] ) ) {
			$conditions[] = 'created_at >= %s';
			$bindings[]   = $filters['date_from'] . ' 00:00:00';
		}
		if ( ! empty( $filters['date_to'] ) ) {
			$conditions[] = 'created_at <= %s';
			$bindings[]   = $filters['date_to'] . ' 23:59:59';
		}

		return array( $conditions, $bindings );
	}

	/**
	 * Создаёт новую запись в журнале аудита.
	 *
	 * @param AuditLogInputDTO $dto DTO с полями для вставки
	 *
	 * @return int ID созданной записи
	 */
	public function create( AuditLogInputDTO $dto ): int {
		$this->wpdb->insert( $this->table, $dto->toArray() );
		return (int) $this->wpdb->insert_id;
	}

	public function listByTarget( string $targetType, int $targetId, int $limit = 50 ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE target_type = %s AND target_id = %d ORDER BY created_at DESC LIMIT %d',
				$this->table,
				$targetType,
				$targetId,
				$limit
			),
			ARRAY_A
		);

		return array_map( fn( array $row ) => AuditLogDTO::fromArray( $row ), $rows ?: array() );
	}

	public function listByActor( int $userId, int $limit = 50 ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE actor_user_id = %d ORDER BY created_at DESC LIMIT %d',
				$this->table,
				$userId,
				$limit
			),
			ARRAY_A
		);

		return array_map( fn( array $row ) => AuditLogDTO::fromArray( $row ), $rows ?: array() );
	}

	public function purgeOlderThan( int $days ): int {
		$this->wpdb->query(
			$this->wpdb->prepare(
				'DELETE FROM %i WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)',
				$this->table,
				$days
			)
		);

		return (int) $this->wpdb->rows_affected;
	}

	/**
	 * Обновление записей аудита запрещено по архитектурным причинам.
	 *
	 * @param int   $id   ID записи
	 * @param array $data Массив обновляемых полей
	 *
	 * @throws \BadMethodCallException Всегда выбрасывает исключение
	 */
	public function update( int $id, array $data ): bool {
		throw new \BadMethodCallException( 'Журнал аудита системы неизменяем.' );
	}
}