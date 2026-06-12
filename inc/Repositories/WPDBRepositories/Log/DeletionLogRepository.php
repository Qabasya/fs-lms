<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories\Log;

use Inc\DTO\Log\DeletionLogDTO;
use Inc\DTO\Log\DeletionLogInputDTO;
use Inc\Enums\TableName;

/**
 * Class DeletionLogRepository
 *
 * Репозиторий для работы с журналом удалений сущностей (deletion_log).
 *
 * @package Inc\Repositories\WPDBRepositories
 *
 * ### Основные обязанности:
 *
 * 1. **Запись удалений сущностей** — создание записей при физическом удалении групп, периодов, студентов и т.д.
 * 2. **Список с фильтрацией** — получение записей с поддержкой фильтров и пагинации.
 * 3. **Получение всех записей** — для экспорта в CSV.
 *
 * ### Архитектурная роль:
 *
 * Инкапсулирует вызовы $wpdb для прямых SQL-запросов.
 * Использует DTO DeletionLogDTO для чтения и DeletionLogInputDTO для вставки.
 * Лог удалений отслеживает, кто и когда удалил сущность,
 * а также какие каскадные удаления были выполнены.
 *
 * ### Фильтры:
 *
 * - actor_user_id — ID пользователя, выполнившего удаление
 * - entity_type — тип удалённой сущности (group, period, student, parent)
 * - date_from — дата начала периода
 * - date_to — дата окончания периода
 */
class DeletionLogRepository {

	private \wpdb $wpdb;
	private string $table;

	/**
	 * Конструктор репозитория.
	 *
	 * @param \wpdb|null $wpdb Глобальный объект базы данных WordPress
	 */
	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb  = $wpdb ?? $GLOBALS['wpdb'];
		$this->table = TableName::DeletionLog->prefixed();
	}

	/**
	 * Создаёт новую запись в журнале удалений.
	 *
	 * @param DeletionLogInputDTO $input DTO с данными для вставки
	 *
	 * @return int ID созданной записи
	 */
	public function create( DeletionLogInputDTO $input ): int {
		$this->wpdb->insert( $this->table, $input->toArray() );
		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Возвращает список записей с фильтрацией и пагинацией.
	 *
	 * @param array $filters Массив фильтров (actor_user_id, entity_type, date_from, date_to)
	 * @param int   $page    Номер страницы
	 * @param int   $perPage Количество записей на страницу
	 *
	 * @return DeletionLogDTO[]
	 */
	public function list( array $filters, int $page, int $perPage ): array {
		[ $conditions, $bindings ] = $this->buildConditions( $filters );
		$where      = implode( ' AND ', $conditions );
		$bindings[] = $perPage;
		$bindings[] = ( $page - 1 ) * $perPage;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT * FROM %i WHERE $where ORDER BY id DESC LIMIT %d OFFSET %d", $bindings ),
			ARRAY_A
		);

		return array_map( fn( array $row ) => DeletionLogDTO::fromArray( $row ), $rows ?: array() );
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
	 * @return DeletionLogDTO[]
	 */
	public function listAll( array $filters ): array {
		[ $conditions, $bindings ] = $this->buildConditions( $filters );
		$where = implode( ' AND ', $conditions );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT * FROM %i WHERE $where ORDER BY id DESC", $bindings ),
			ARRAY_A
		);

		return array_map( fn( array $row ) => DeletionLogDTO::fromArray( $row ), $rows ?: array() );
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

		// Фильтр по ID пользователя, выполнившего удаление
		if ( ! empty( $filters['actor_user_id'] ) ) {
			$conditions[] = 'actor_user_id = %d';
			$bindings[]   = (int) $filters['actor_user_id'];
		}

		// Фильтр по типу удалённой сущности
		if ( ! empty( $filters['entity_type'] ) ) {
			$conditions[] = 'entity_type = %s';
			$bindings[]   = $filters['entity_type'];
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