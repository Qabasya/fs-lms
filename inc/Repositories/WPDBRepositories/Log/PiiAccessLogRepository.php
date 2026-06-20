<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories\Log;

use Inc\DTO\Person\PiiAccessLogDTO;
use Inc\DTO\Person\PiiAccessLogInputDTO;
use Inc\Enums\Settings\TableName;

/**
 * Class PiiAccessLogRepository
 *
 * Репозиторий для ведения строгого журнала доступа сотрудников к ПД физлиц.
 *
 * @package Inc\Repositories\WPDBRepositories
 *
 * ### Основные обязанности:
 *
 * 1. **Запись доступа** — фиксация факта доступа сотрудника к персональным данным.
 * 2. **Поиск записей** — получение записей по ID, по ID человека.
 *
 * ### Архитектурная роль:
 *
 * Реализует интерфейс RepositoryInterface для единообразия с другими репозиториями.
 * Использует wpdb для прямых SQL-запросов. Записи журнала PII Access являются
 * неизменяемыми для обеспечения compliance (update() выбрасывает исключение).
 *
 * ### Compliance (соответствие законодательству):
 *
 * Журнал создаётся для отслеживания каждого случая доступа к персональным данным.
 * Фиксируется: кто запрашивал (actor_user_id), к каким данным (fields_accessed),
 * причина доступа (access_reason), IP-адрес, время.
 */
class PiiAccessLogRepository {

	private \wpdb $wpdb;
	private string $table;

	/**
	 * Конструктор репозитория.
	 *
	 * @param \wpdb|null $wpdb Глобальный объект базы данных WordPress
	 */
	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb  = $wpdb ?? $GLOBALS['wpdb'];
		$this->table = TableName::PiiAccessLog->prefixed();
	}

	/**
	 * Находит запись журнала по ID.
	 *
	 * @param int $id ID записи
	 *
	 * @return PiiAccessLogDTO|null
	 */
	public function find( int $id ): ?PiiAccessLogDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', $this->table, $id ),
			ARRAY_A
		);

		return $row ? PiiAccessLogDTO::fromArray( $row ) : null;
	}

	/**
	 * Находит все записи доступа к персональным данным конкретного человека.
	 *
	 * @param int $personId ID человека из таблицы persons
	 *
	 * @return PiiAccessLogDTO[]
	 */
	public function findByPerson( int $personId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE person_id = %d ORDER BY created_at DESC',
				$this->table,
				$personId
			),
			ARRAY_A
		);

		return array_map( fn( array $row ) => PiiAccessLogDTO::fromArray( $row ), $rows ?: array() );
	}

	/**
	 * Создаёт новую запись доступа к персональным данным.
	 *
	 * @param PiiAccessLogInputDTO $input Входные данные записи доступа
	 *
	 * @return int ID созданной записи
	 */
	public function create( PiiAccessLogInputDTO $input ): int {
		$this->wpdb->insert( $this->table, $input->toArray() );
		return (int) $this->wpdb->insert_id;
	}

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

		return array_map( fn( array $row ) => PiiAccessLogDTO::fromArray( $row ), $rows ?: array() );
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

		return array_map( fn( array $row ) => PiiAccessLogDTO::fromArray( $row ), $rows ?: array() );
	}

	public function countByActorInLastHour( int $userId ): int {
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE actor_user_id = %d AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)',
				$this->table,
				$userId
			)
		);
	}

	private function buildConditions( array $filters ): array {
		$conditions = array( '1=1' );
		$bindings   = array( $this->table );

		if ( ! empty( $filters['actor_user_id'] ) ) {
			$conditions[] = 'actor_user_id = %d';
			$bindings[]   = (int) $filters['actor_user_id'];
		}
		if ( ! empty( $filters['person_id'] ) ) {
			$conditions[] = 'person_id = %d';
			$bindings[]   = (int) $filters['person_id'];
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

	public function listByPerson( int $personId, int $limit = 50 ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE person_id = %d ORDER BY created_at DESC LIMIT %d',
				$this->table,
				$personId,
				$limit
			),
			ARRAY_A
		);

		return array_map( fn( array $row ) => PiiAccessLogDTO::fromArray( $row ), $rows ?: array() );
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

		return array_map( fn( array $row ) => PiiAccessLogDTO::fromArray( $row ), $rows ?: array() );
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
	 * Обновление записей журнала доступа к ПД запрещено по compliance-требованиям.
	 *
	 * @param int   $id   ID записи
	 * @param array $data Массив обновляемых полей
	 *
	 * @throws \BadMethodCallException Всегда выбрасывает исключение
	 */
	public function update( int $id, array $data ): bool {
		throw new \BadMethodCallException( 'Журнал доступа к персональным данным защищён от изменений.' );
	}
}