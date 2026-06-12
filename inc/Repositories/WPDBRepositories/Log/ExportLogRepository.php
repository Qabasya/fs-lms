<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories\Log;

use Inc\DTO\Log\ExportLogDTO;
use Inc\DTO\Log\ExportLogInputDTO;
use Inc\Enums\TableName;

/**
 * Class ExportLogRepository
 *
 * Репозиторий для работы с журналом экспорта данных (export_log).
 *
 * @package Inc\Repositories\WPDBRepositories
 *
 * ### Основные обязанности:
 *
 * 1. **Запись экспорта данных** — создание записей при экспорте групп, студентов, родителей, архива, логов.
 * 2. **Список с фильтрацией** — получение записей с поддержкой фильтров и пагинации.
 * 3. **Получение всех записей** — для экспорта в CSV (двойной экспорт).
 *
 * ### Архитектурная роль:
 *
 * Инкапсулирует вызовы $wpdb для прямых SQL-запросов.
 * Использует DTO ExportLogDTO для чтения и ExportLogInputDTO для вставки.
 * Журнал экспорта отслеживает, кто и когда выгружал данные,
 * а также какие ID были экспортированы (для единичных экспортов).
 *
 * ### Фильтры:
 *
 * - actor_user_id — ID пользователя, выполнившего экспорт
 * - data_type — тип экспортируемых данных (groups, students, parents, archive, log_audit и т.д.)
 * - date_from — дата начала периода
 * - date_to — дата окончания периода
 */
class ExportLogRepository {

	private \wpdb $wpdb;
	private string $table;

	/**
	 * Конструктор репозитория.
	 *
	 * @param \wpdb|null $wpdb Глобальный объект базы данных WordPress
	 */
	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb  = $wpdb ?? $GLOBALS['wpdb'];
		$this->table = TableName::ExportLog->prefixed();
	}

	/**
	 * Создаёт новую запись в журнале экспорта данных.
	 *
	 * @param ExportLogInputDTO $input DTO с данными для вставки
	 *
	 * @return int ID созданной записи
	 */
	public function create( ExportLogInputDTO $input ): int {
		$this->wpdb->insert( $this->table, $input->toArray() );
		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Возвращает список записей с фильтрацией и пагинацией.
	 *
	 * @param array $filters Массив фильтров (actor_user_id, data_type, date_from, date_to)
	 * @param int   $page    Номер страницы
	 * @param int   $perPage Количество записей на страницу
	 *
	 * @return ExportLogDTO[]
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

		return array_map( fn( array $row ) => ExportLogDTO::fromArray( $row ), $rows ?: array() );
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
	 * @return ExportLogDTO[]
	 */
	public function listAll( array $filters ): array {
		[ $conditions, $bindings ] = $this->buildConditions( $filters );
		$where = implode( ' AND ', $conditions );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT * FROM %i WHERE $where ORDER BY id DESC", $bindings ),
			ARRAY_A
		);

		return array_map( fn( array $row ) => ExportLogDTO::fromArray( $row ), $rows ?: array() );
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

		// Фильтр по ID пользователя, выполнившего экспорт
		if ( ! empty( $filters['actor_user_id'] ) ) {
			$conditions[] = 'actor_user_id = %d';
			$bindings[]   = (int) $filters['actor_user_id'];
		}

		// Фильтр по типу экспортируемых данных
		if ( ! empty( $filters['data_type'] ) ) {
			$conditions[] = 'data_type = %s';
			$bindings[]   = $filters['data_type'];
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