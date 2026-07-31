<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories\Log;

use Inc\Enums\Log\LogChannel;
use Inc\Enums\Log\LogFilterType;

/**
 * Class AbstractLogRepository
 *
 * Общий механизм чтения журналов: все каналы лежат в однотипных таблицах
 * (`id`, `created_at`, набор колонок канала) и читаются одинаково —
 * список с фильтрами и пагинацией, счётчик, полная выгрузка для экспорта.
 *
 * @package Inc\Repositories\WPDBRepositories\Log
 *
 * ### Что переопределяет наследник
 *
 * 1. {@see channel()} — канал (из него берётся таблица).
 * 2. {@see filterMap()} — поддерживаемые фильтры: `ключ фильтра => [колонка, тип]`.
 *    Фильтры по датам (`date_from`/`date_to`) добавляются автоматически.
 * 3. {@see hydrate()} — строка таблицы → DTO канала.
 *
 * Наследник добавляет только своё: `create()` (через {@see insertRow()}),
 * словари значений для UI (через {@see distinctValues()} / {@see distinctIntValues()})
 * и специфические выборки.
 */
abstract class AbstractLogRepository {

	protected \wpdb $wpdb;
	protected string $table;

	/**
	 * @param \wpdb|null $wpdb Глобальный объект базы данных WordPress
	 */
	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb  = $wpdb ?? $GLOBALS['wpdb'];
		$this->table = $this->channel()->tableName()->prefixed();
	}

	/**
	 * Канал журнала — определяет таблицу хранения.
	 */
	abstract protected function channel(): LogChannel;

	/**
	 * Поддерживаемые фильтры канала.
	 *
	 * @return array<string, array{0: string, 1: LogFilterType}> [ключ фильтра => [колонка, тип]]
	 */
	abstract protected function filterMap(): array;

	/**
	 * Превращает строку таблицы в DTO канала.
	 *
	 * @param array<string, mixed> $row Строка результата запроса
	 */
	abstract protected function hydrate( array $row ): object;

	/**
	 * Возвращает список записей с фильтрацией и пагинацией.
	 *
	 * @param array  $filters Фильтры канала (см. filterMap()) + date_from/date_to
	 * @param int    $page    Номер страницы (с единицы)
	 * @param int    $perPage Записей на страницу
	 * @param string $orderby Сортировка: id|created_at
	 * @param string $order   Направление: ASC|DESC
	 *
	 * @return object[] DTO канала
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

		return $this->hydrateAll( $rows );
	}

	/**
	 * Подсчитывает количество записей по заданным фильтрам.
	 *
	 * @param array $filters Фильтры канала
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
	 * Возвращает все записи по фильтрам (без пагинации) — для экспорта.
	 *
	 * @param array $filters Фильтры канала
	 *
	 * @return object[] DTO канала
	 */
	public function listAll( array $filters ): array {
		[ $conditions, $bindings ] = $this->buildConditions( $filters );
		$where = implode( ' AND ', $conditions );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT * FROM %i WHERE $where ORDER BY id DESC", $bindings ),
			ARRAY_A
		);

		return $this->hydrateAll( $rows );
	}

	/**
	 * Удаляет записи старше указанного количества дней.
	 *
	 * @param int $days Возраст записей в днях
	 *
	 * @return int Количество удалённых строк
	 */
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
	 * Формирует WHERE-условия и массив параметров prepare() по filterMap() и датам.
	 *
	 * @param array $filters Фильтры канала
	 *
	 * @return array{0: string[], 1: array}
	 */
	protected function buildConditions( array $filters ): array {
		$conditions = array( '1=1' );
		$bindings   = array( $this->table );

		foreach ( $this->filterMap() as $key => [ $column, $type ] ) {
			$this->applyFilter( $conditions, $bindings, $filters[ $key ] ?? null, $column, $type );
		}

		// Диапазон дат — общий для всех каналов (границы суток включительно)
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
	 * Добавляет одно условие фильтра к запросу.
	 *
	 * @param string[]      $conditions Накопитель условий (по ссылке)
	 * @param array         $bindings   Накопитель параметров prepare() (по ссылке)
	 * @param mixed         $value      Значение фильтра из запроса
	 * @param string        $column     Колонка таблицы
	 * @param LogFilterType $type       Тип значения
	 *
	 * @return void
	 */
	private function applyFilter( array &$conditions, array &$bindings, mixed $value, string $column, LogFilterType $type ): void {
		if ( LogFilterType::NumberList === $type ) {
			if ( ! is_array( $value ) ) {
				return;
			}

			// Явно переданный пустой список = «ничего не подходит», а не «фильтра нет»
			if ( empty( $value ) ) {
				$conditions[] = '1=0';
				return;
			}

			$placeholders = implode( ', ', array_fill( 0, count( $value ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$conditions[] = "$column IN ($placeholders)";
			foreach ( $value as $item ) {
				$bindings[] = (int) $item;
			}

			return;
		}

		if ( empty( $value ) ) {
			return;
		}

		if ( LogFilterType::Number === $type ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$conditions[] = "$column = %d";
			$bindings[]   = (int) $value;

			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$conditions[] = "$column = %s";
		$bindings[]   = $value;
	}

	/**
	 * Вставляет строку в таблицу канала.
	 *
	 * @param array<string, mixed> $data Поля записи (обычно InputDTO::toArray())
	 *
	 * @return int ID созданной записи
	 */
	protected function insertRow( array $data ): int {
		$this->wpdb->insert( $this->table, $data );

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Уникальные непустые значения колонки — словарь для фильтров UI.
	 *
	 * @param string $column Колонка таблицы
	 *
	 * @return string[]
	 */
	protected function distinctValues( string $column ): array {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$values = $this->wpdb->get_col(
			$this->wpdb->prepare( "SELECT DISTINCT $column FROM %i WHERE $column IS NOT NULL ORDER BY $column", $this->table )
		);

		return $values ?: array();
	}

	/**
	 * То же, что {@see distinctValues()}, но приводит значения к int.
	 *
	 * @param string $column Колонка таблицы
	 *
	 * @return int[]
	 */
	protected function distinctIntValues( string $column ): array {
		return array_map( 'intval', $this->distinctValues( $column ) );
	}

	/**
	 * Превращает набор строк в DTO канала.
	 *
	 * @param array<int, array<string, mixed>>|null $rows Результат get_results()
	 *
	 * @return object[]
	 */
	protected function hydrateAll( ?array $rows ): array {
		return array_map( fn( array $row ): object => $this->hydrate( $row ), $rows ?: array() );
	}
}
