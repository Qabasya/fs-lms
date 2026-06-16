<?php

declare(strict_types=1);

/**
 * Программируемый дубль wpdb для интеграционных тестов репозиториев.
 *
 * Подменяет реальную БД, но честно прогоняет SQL через prepare()
 * (с подстановкой плейсхолдеров), чтобы тесты могли проверять,
 * какой именно запрос строит репозиторий, и как он мапит строки в DTO.
 *
 * Возвраты задаются очередями (FIFO): queueRow()/queueVar()/queueResults().
 * Записи (insert/update/delete) перехватываются в публичные массивы.
 */
class FakeWpdb extends \wpdb {

	/** @var string[] Все выполненные SELECT-запросы (после prepare). */
	public array $queries = array();

	/** @var array<int, mixed> Очередь возвратов для get_row(). */
	private array $rowReturns = array();

	/** @var array<int, mixed> Очередь возвратов для get_var(). */
	private array $varReturns = array();

	/** @var array<int, array> Очередь возвратов для get_results(). */
	private array $resultsReturns = array();

	/** @var array<int, array{table:string,data:array}> */
	public array $inserts = array();

	/** @var array<int, array{table:string,data:array,where:array}> */
	public array $updates = array();

	/** @var array<int, array{table:string,where:array}> */
	public array $deletes = array();

	public function queueRow( mixed $row ): self {
		$this->rowReturns[] = $row;
		return $this;
	}

	public function queueVar( mixed $var ): self {
		$this->varReturns[] = $var;
		return $this;
	}

	public function queueResults( array $rows ): self {
		$this->resultsReturns[] = $rows;
		return $this;
	}

	public function lastQuery(): string {
		return $this->queries ? (string) end( $this->queries ) : '';
	}

	public function prepare( string $sql, ...$args ): string {
		// Репозитории иногда передают массив биндингов одним аргументом.
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		$i = 0;
		return (string) preg_replace_callback(
			'/%[dsfi]/',
			static function ( array $m ) use ( &$i, $args ): string {
				$val = $args[ $i ] ?? '';
				$i++;
				return match ( $m[0] ) {
					'%d'    => (string) (int) $val,
					'%f'    => (string) (float) $val,
					'%i'    => '`' . $val . '`',
					default => "'" . $val . "'",
				};
			},
			$sql
		);
	}

	public function query( string $query ): bool|int {
		$this->queries[] = $query;
		return 1;
	}

	public function get_row( string $query, string $output = 'OBJECT', int $y = 0 ): mixed {
		$this->queries[] = $query;
		return array_shift( $this->rowReturns );
	}

	public function get_var( string $query, int $x = 0, int $y = 0 ): mixed {
		$this->queries[] = $query;
		return array_shift( $this->varReturns );
	}

	public function get_results( string $query, string $output = 'OBJECT' ): array {
		$this->queries[] = $query;
		return array_shift( $this->resultsReturns ) ?? array();
	}

	public function insert( string $table, array $data, ?array $format = null ): int|false {
		$this->inserts[] = array( 'table' => $table, 'data' => $data );
		return 1;
	}

	public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int|false {
		$this->updates[] = array( 'table' => $table, 'data' => $data, 'where' => $where );
		return 1;
	}

	public function delete( string $table, array $where, $where_format = null ): int|false {
		$this->deletes[] = array( 'table' => $table, 'where' => $where );
		return 1;
	}
}
