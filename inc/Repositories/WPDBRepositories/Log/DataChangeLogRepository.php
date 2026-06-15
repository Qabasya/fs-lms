<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories\Log;

use Inc\DTO\Log\DataChangeLogDTO;
use Inc\DTO\Log\DataChangeLogInputDTO;
use Inc\Enums\TableName;

/**
 * Class DataChangeLogRepository
 *
 * Репозиторий для работы с журналом изменений персональных данных (data_change_log).
 *
 * @package Inc\Repositories\WPDBRepositories
 *
 * ### Основные обязанности:
 *
 * 1. **Запись изменений данных** — создание записей при изменении полей лица (ФИО, документы, контакты).
 * 2. **Список с фильтрацией** — получение записей с поддержкой фильтров и пагинации.
 * 3. **Получение всех записей** — для экспорта в CSV.
 *
 * ### Архитектурная роль:
 *
 * Инкапсулирует вызовы $wpdb для прямых SQL-запросов.
 * Использует DTO DataChangeLogDTO для чтения и DataChangeLogInputDTO для вставки.
 * Лог изменений данных отслеживает, кто и когда изменял персональные данные,
 * а также старые и новые значения (в зашифрованном виде).
 *
 * ### Фильтры:
 *
 * - actor_user_id — ID пользователя, изменившего данные
 * - target_person_id — ID лица, чьи данные изменены
 * - field_name — название изменённого поля
 * - date_from — дата начала периода
 * - date_to — дата окончания периода
 */
class DataChangeLogRepository {

	private \wpdb $wpdb;
	private string $table;

	/**
	 * Конструктор репозитория.
	 *
	 * @param \wpdb|null $wpdb Глобальный объект базы данных WordPress
	 */
	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb  = $wpdb ?? $GLOBALS['wpdb'];
		$this->table = TableName::DataChangeLog->prefixed();
	}

	/**
	 * Создаёт новую запись в журнале изменений данных.
	 *
	 * @param DataChangeLogInputDTO $input DTO с данными для вставки
	 *
	 * @return int ID созданной записи
	 */
	public function create( DataChangeLogInputDTO $input ): int {
		$this->wpdb->insert( $this->table, $input->toArray() );
		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Возвращает список записей с фильтрацией и пагинацией.
	 *
	 * @param array $filters Массив фильтров (actor_user_id, target_person_id, field_name, date_from, date_to)
	 * @param int   $page    Номер страницы
	 * @param int   $perPage Количество записей на страницу
	 *
	 * @return DataChangeLogDTO[]
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

		return array_map( fn( array $row ) => DataChangeLogDTO::fromArray( $row ), $rows ?: array() );
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
	 * @return DataChangeLogDTO[]
	 */
	public function listAll( array $filters ): array {
		[ $conditions, $bindings ] = $this->buildConditions( $filters );
		$where = implode( ' AND ', $conditions );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT * FROM %i WHERE $where ORDER BY id DESC", $bindings ),
			ARRAY_A
		);

		return array_map( fn( array $row ) => DataChangeLogDTO::fromArray( $row ), $rows ?: array() );
	}

	/**
	 * Формирует WHERE-условие и массив параметров для запроса.
	 *
	 * @param array $filters Массив фильтров
	 *
	 * @return array{0: string[], 1: array}
	 */
	public function distinctActorUserIds(): array {
		return array_map(
			'intval',
			$this->wpdb->get_col(
				$this->wpdb->prepare( 'SELECT DISTINCT actor_user_id FROM %i WHERE actor_user_id IS NOT NULL ORDER BY actor_user_id', $this->table )
			) ?: array()
		);
	}

	public function distinctPersonIds(): array {
		return array_map(
			'intval',
			$this->wpdb->get_col(
				$this->wpdb->prepare( 'SELECT DISTINCT target_person_id FROM %i WHERE target_person_id IS NOT NULL ORDER BY target_person_id', $this->table )
			) ?: array()
		);
	}

	private function buildConditions( array $filters ): array {
		$conditions = array( '1=1' );
		$bindings   = array( $this->table );

		// Фильтр по ID пользователя, изменившего данные
		if ( ! empty( $filters['actor_user_id'] ) ) {
			$conditions[] = 'actor_user_id = %d';
			$bindings[]   = (int) $filters['actor_user_id'];
		}

		// Фильтр по ID лица, чьи данные изменены
		if ( ! empty( $filters['target_person_id'] ) ) {
			$conditions[] = 'target_person_id = %d';
			$bindings[]   = (int) $filters['target_person_id'];
		}

		// Фильтр по названию поля
		if ( ! empty( $filters['field_name'] ) ) {
			$conditions[] = 'field_name = %s';
			$bindings[]   = $filters['field_name'];
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