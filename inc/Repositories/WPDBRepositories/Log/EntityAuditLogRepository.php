<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories\Log;

use Inc\DTO\Log\EntityAuditLogDTO;
use Inc\DTO\Log\EntityAuditLogInputDTO;
use Inc\Enums\TableName;

/**
 * Class EntityAuditLogRepository
 *
 * Репозиторий для работы с журналом аудита изменений сущностей (entity_audit_log).
 *
 * @package Inc\Repositories\WPDBRepositories
 *
 * ### Основные обязанности:
 *
 * 1. **Запись изменений сущностей** — создание записей при создании, обновлении или удалении сущностей
 *    (предметы, таксономии, задания, статьи, группы, периоды, пользователи).
 * 2. **Список с фильтрацией** — получение записей с поддержкой фильтров и пагинации.
 * 3. **Получение всех записей** — для экспорта в CSV.
 *
 * ### Архитектурная роль:
 *
 * Инкапсулирует вызовы $wpdb для прямых SQL-запросов.
 * Использует DTO EntityAuditLogDTO для чтения и EntityAuditLogInputDTO для вставки.
 * Журнал аудита сущностей отслеживает, кто и когда изменял различные сущности
 * в административной панели.
 *
 * ### Фильтры:
 *
 * - operation — тип операции (create, update, delete)
 * - entity_type — тип сущности (subject, taxonomy, task, article и т.д.)
 * - actor_user_id — ID пользователя, выполнившего действие
 * - date_from — дата начала периода
 * - date_to — дата окончания периода
 */
class EntityAuditLogRepository {

	private \wpdb  $wpdb;
	private string $table;

	/**
	 * Конструктор репозитория.
	 *
	 * @param \wpdb|null $wpdb Глобальный объект базы данных WordPress
	 */
	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb  = $wpdb ?? $GLOBALS['wpdb'];
		$this->table = TableName::EntityAuditLog->prefixed();
	}

	/**
	 * Создаёт новую запись в журнале аудита сущностей.
	 *
	 * @param EntityAuditLogInputDTO $input DTO с данными для вставки
	 *
	 * @return int ID созданной записи
	 */
	public function create( EntityAuditLogInputDTO $input ): int {
		$this->wpdb->insert( $this->table, $input->toArray() );
		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Возвращает список записей с фильтрацией и пагинацией.
	 *
	 * @param array $filters Массив фильтров (operation, entity_type, actor_user_id, date_from, date_to)
	 * @param int   $page    Номер страницы
	 * @param int   $perPage Количество записей на страницу
	 *
	 * @return EntityAuditLogDTO[]
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

		return array_map( fn( array $row ) => EntityAuditLogDTO::fromArray( $row ), $rows ?: array() );
	}

	/**
	 * Подсчитывает количество записей по заданным фильтрам.
	 *
	 * @param array $filters Массив фильтров
	 *
	 * @return int
	 */
	public function countFiltered( array $filters ): int {
		[ $conditions, $bindings ] = $this->buildConditions( $filters );
		$where = implode( ' AND ', $conditions );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE $where", $bindings )
		);
	}

	/**
	 * Возвращает все записи по фильтрам (без пагинации) для экспорта.
	 *
	 * @param array $filters Массив фильтров
	 *
	 * @return EntityAuditLogDTO[]
	 */
	public function listAll( array $filters ): array {
		[ $conditions, $bindings ] = $this->buildConditions( $filters );
		$where = implode( ' AND ', $conditions );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT * FROM %i WHERE $where ORDER BY id DESC", $bindings ),
			ARRAY_A
		);

		return array_map( fn( array $row ) => EntityAuditLogDTO::fromArray( $row ), $rows ?: array() );
	}

	/**
	 * Формирует WHERE-условие и массив параметров для запроса.
	 *
	 * @param array $filters Массив фильтров
	 *
	 * @return array{0: string[], 1: array}
	 */
	private function buildConditions( array $filters ): array {
		$conditions = array( '1=1' );
		$bindings   = array( $this->table );

		// Фильтр по типу операции
		if ( ! empty( $filters['operation'] ) ) {
			$conditions[] = 'operation = %s';
			$bindings[]   = $filters['operation'];
		}

		// Фильтр по типу сущности
		if ( ! empty( $filters['entity_type'] ) ) {
			$conditions[] = 'entity_type = %s';
			$bindings[]   = $filters['entity_type'];
		}

		// Фильтр по ID пользователя, выполнившего действие
		if ( ! empty( $filters['actor_user_id'] ) ) {
			$conditions[] = 'actor_user_id = %d';
			$bindings[]   = (int) $filters['actor_user_id'];
		}

		// Фильтр по дате начала (с 00:00:00)
		if ( ! empty( $filters['date_from'] ) ) {
			$conditions[] = 'created_at >= %s';
			$bindings[]   = $filters['date_from'] . ' 00:00:00';
		}

		// Фильтр по дате окончания (до 23:59:59)
		if ( ! empty( $filters['date_to'] ) ) {
			$conditions[] = 'created_at <= %s';
			$bindings[]   = $filters['date_to'] . ' 23:59:59';
		}

		return array( $conditions, $bindings );
	}
}