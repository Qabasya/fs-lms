<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories\Log;

use Inc\DTO\Log\AuthLogDTO;
use Inc\DTO\Log\AuthLogInputDTO;
use Inc\Enums\Settings\TableName;

/**
 * Class AuthLogRepository
 *
 * Репозиторий для работы с журналом аутентификации (auth_log).
 *
 * @package Inc\Repositories\WPDBRepositories
 *
 * ### Основные обязанности:
 *
 * 1. **Запись событий аутентификации** — создание записей в таблице auth_log.
 * 2. **Список с фильтрацией** — получение записей с поддержкой фильтров и пагинации.
 * 3. **Получение всех записей** — для экспорта в CSV.
 *
 * ### Архитектурная роль:
 *
 * Инкапсулирует вызовы $wpdb для прямых SQL-запросов.
 * Использует DTO AuthLogDTO для чтения и AuthLogInputDTO для вставки.
 * Журнал аутентификации отслеживает: успешные/неудачные входы, сбросы пароля.
 *
 * ### Фильтры:
 *
 * - action — тип действия (login, login_failed, password_reset)
 * - result — результат (success/failed)
 * - date_from — дата начала периода
 * - date_to — дата окончания периода
 */
class AuthLogRepository {

	private \wpdb $wpdb;
	private string $table;

	/**
	 * Конструктор репозитория.
	 *
	 * @param \wpdb|null $wpdb Глобальный объект базы данных WordPress
	 */
	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb  = $wpdb ?? $GLOBALS['wpdb'];
		$this->table = TableName::AuthLog->prefixed();
	}

	/**
	 * Создаёт новую запись в журнале аутентификации.
	 *
	 * @param AuthLogInputDTO $input DTO с данными для вставки
	 *
	 * @return int ID созданной записи
	 */
	public function create( AuthLogInputDTO $input ): int {
		$this->wpdb->insert( $this->table, $input->toArray() );
		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Возвращает список записей с фильтрацией и пагинацией.
	 *
	 * @param array $filters Массив фильтров (action, result, date_from, date_to)
	 * @param int   $page    Номер страницы
	 * @param int   $perPage Количество записей на страницу
	 *
	 * @return AuthLogDTO[]
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

		return array_map( fn( array $row ) => AuthLogDTO::fromArray( $row ), $rows ?: array() );
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
	 * @return AuthLogDTO[]
	 */
	public function listAll( array $filters ): array {
		[ $conditions, $bindings ] = $this->buildConditions( $filters );
		$where = implode( ' AND ', $conditions );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT * FROM %i WHERE $where ORDER BY id DESC", $bindings ),
			ARRAY_A
		);

		return array_map( fn( array $row ) => AuthLogDTO::fromArray( $row ), $rows ?: array() );
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

		// Фильтр по типу действия
		if ( ! empty( $filters['action'] ) ) {
			$conditions[] = 'action = %s';
			$bindings[]   = $filters['action'];
		}

		// Фильтр по результату
		if ( ! empty( $filters['result'] ) ) {
			$conditions[] = 'result = %s';
			$bindings[]   = $filters['result'];
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

	public function distinctActions(): array {
		$actions = $this->wpdb->get_col(
			$this->wpdb->prepare(
				'SELECT DISTINCT action
			 FROM %i
			 WHERE action IS NOT NULL
			 ORDER BY action',
				$this->table
			)
		);

		return $actions ?: array();
	}
}