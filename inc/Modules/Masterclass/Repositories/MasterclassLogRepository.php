<?php

declare( strict_types=1 );

namespace Inc\Modules\Masterclass\Repositories;

use Inc\Modules\Masterclass\DTO\MasterclassLogDTO;
use Inc\Modules\Masterclass\DTO\MasterclassLogInputDTO;
use Inc\Modules\Masterclass\Schema\MasterclassSchema;
use Inc\Repositories\WPDBRepositories\AbstractExamRepository;

/**
 * Class MasterclassLogRepository
 *
 * Журнал модуля (`fs_lms_masterclass_log`). Не наследует `AbstractLogRepository`: тот привязан к core-энуму
 * `LogChannel` (канал → таблица), а ядро о модулях не знает. Поэтому чтение, фильтры и очистка свои.
 *
 * ### Фильтры `list()`
 *
 * - `action` — значение {@see \Inc\Modules\Masterclass\Enums\MasterclassLogAction}
 * - `masterclass_id`, `account_id` — объект действия
 * - `date_from`, `date_to` — `Y-m-d`, границы суток включительно, в UTC (как у журналов ядра)
 *
 * @package Inc\Modules\Masterclass\Repositories
 */
class MasterclassLogRepository extends AbstractExamRepository {

	private string $table;

	public function __construct( ?\wpdb $wpdb = null ) {
		parent::__construct( $wpdb );
		$this->table = MasterclassSchema::log();
	}

	public function create( MasterclassLogInputDTO $input ): int {
		return $this->insertRow( $this->table, $input->toArray() );
	}

	/**
	 * Страница журнала, новые сверху.
	 *
	 * @param array<string, mixed> $filters
	 *
	 * @return array{rows: list<MasterclassLogDTO>, total: int}
	 */
	public function list( array $filters, int $page, int $perPage ): array {
		$perPage = max( 1, $perPage );
		$offset  = ( max( 1, $page ) - 1 ) * $perPage;

		[ $where, $args ] = $this->conditions( $filters );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where собрано из констант и плейсхолдеров в conditions().
		$rows  = $this->readRows(
			$this->wpdb->prepare(
				"SELECT * FROM %i WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d",
				$this->table,
				...array_merge( $args, array( $perPage, $offset ) )
			)
		);
		$total = $this->readInt( $this->wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$where}", $this->table, ...$args ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array(
			'rows'  => array_map( array( MasterclassLogDTO::class, 'fromArray' ), $rows ),
			'total' => $total,
		);
	}

	/**
	 * Действия, встречающиеся в журнале, — словарь фильтра UI.
	 *
	 * @return list<string>
	 */
	public function distinctActions(): array {
		$rows = $this->readRows( $this->wpdb->prepare( 'SELECT DISTINCT action FROM %i ORDER BY action ASC', $this->table ) );

		return array_map( 'strval', array_column( $rows, 'action' ) );
	}

	/**
	 * Удаляет записи старше границы (срок хранения).
	 *
	 * @param string $utc Граница, UTC `Y-m-d H:i:s`.
	 *
	 * @return int Сколько записей удалено.
	 */
	public function purgeOlderThan( string $utc ): int {
		return $this->write( $this->wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', $this->table, $utc ) );
	}

	/**
	 * @param array<string, mixed> $filters
	 *
	 * @return array{0: string, 1: list<int|string>} Условие WHERE и значения его плейсхолдеров.
	 */
	private function conditions( array $filters ): array {
		$where = array( '1=1' );
		$args  = array();

		if ( ! empty( $filters['action'] ) ) {
			$where[] = 'action = %s';
			$args[]  = (string) $filters['action'];
		}
		if ( ! empty( $filters['masterclass_id'] ) ) {
			$where[] = 'masterclass_id = %d';
			$args[]  = (int) $filters['masterclass_id'];
		}
		if ( ! empty( $filters['account_id'] ) ) {
			$where[] = 'account_id = %d';
			$args[]  = (int) $filters['account_id'];
		}
		if ( ! empty( $filters['date_from'] ) ) {
			$where[] = 'created_at >= %s';
			$args[]  = $filters['date_from'] . ' 00:00:00';
		}
		if ( ! empty( $filters['date_to'] ) ) {
			$where[] = 'created_at <= %s';
			$args[]  = $filters['date_to'] . ' 23:59:59';
		}

		return array( implode( ' AND ', $where ), $args );
	}
}
