<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\DTO\Lead\LeadDTO;
use Inc\DTO\Lead\LeadInputDTO;
use Inc\Enums\Settings\TableName;

/**
 * Class LeadRepository
 *
 * Доступ к таблице fs_lms_leads — заявки с лид-форм сайта (тема передаёт их
 * хуком `fs_lms_theme_lead_submitted`), принятые и отклонённые.
 *
 * Фильтры списка: `verdict` ('accepted' | 'rejected'), `reason`, `subnet`.
 *
 * @package Inc\Repositories\WPDBRepositories
 */
class LeadRepository {

	private \wpdb  $wpdb;
	private string $table;

	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb  = $wpdb ?? $GLOBALS['wpdb'];
		$this->table = TableName::Leads->prefixed();
	}

	/**
	 * @return int ID созданной записи (0 — вставка не удалась).
	 */
	public function create( LeadInputDTO $input ): int {
		$ok = $this->wpdb->insert( $this->table, $input->toArray() );

		return false === $ok ? 0 : (int) $this->wpdb->insert_id;
	}

	/**
	 * @param array<string, string> $filters
	 *
	 * @return LeadDTO[]
	 */
	public function list( array $filters, int $page, int $perPage ): array {
		[ $where, $args ] = $this->buildWhere( $filters );

		$args[] = max( 1, $perPage );
		$args[] = max( 0, ( $page - 1 ) * $perPage );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where собран из фиксированных условий с плейсхолдерами
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT * FROM %i WHERE $where ORDER BY received_at DESC, id DESC LIMIT %d OFFSET %d", array_merge( array( $this->table ), $args ) ),
			ARRAY_A
		);

		return array_map( static fn( array $row ): LeadDTO => LeadDTO::fromArray( $row ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * @param array<string, string> $filters
	 */
	public function count( array $filters ): int {
		[ $where, $args ] = $this->buildWhere( $filters );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- см. list()
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE $where", array_merge( array( $this->table ), $args ) )
		);
	}

	/**
	 * Подсети с наибольшим числом отклонённых заявок — подсказка в таблице.
	 *
	 * @return array<string, int> subnet => число заявок, самые частые сверху.
	 */
	public function topSubnets( int $limit = 5 ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT subnet, COUNT(*) AS cnt FROM %i WHERE subnet <> '' AND verdict = 'rejected' GROUP BY subnet ORDER BY cnt DESC LIMIT %d",
				$this->table,
				$limit
			),
			ARRAY_A
		);

		$result = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$result[ (string) $row['subnet'] ] = (int) $row['cnt'];
		}

		return $result;
	}

	/**
	 * @param int[] $ids
	 *
	 * @return int Сколько записей удалено.
	 */
	public function deleteByIds( array $ids ): int {
		$ids = array_values( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 ) );
		if ( array() === $ids ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- только %d-плейсхолдеры
		return (int) $this->wpdb->query( $this->wpdb->prepare( "DELETE FROM %i WHERE id IN ($placeholders)", array_merge( array( $this->table ), $ids ) ) );
	}

	/**
	 * @return int Сколько записей удалено.
	 */
	public function deleteRejected(): int {
		return (int) $this->wpdb->query( $this->wpdb->prepare( "DELETE FROM %i WHERE verdict = 'rejected'", $this->table ) );
	}

	/**
	 * @param array<string, string> $filters
	 *
	 * @return array{0: string, 1: array<int, string>}
	 */
	private function buildWhere( array $filters ): array {
		$where = array( '1=1' );
		$args  = array();

		foreach ( array( 'verdict', 'reason', 'subnet' ) as $field ) {
			$value = (string) ( $filters[ $field ] ?? '' );
			if ( '' !== $value ) {
				$where[] = "$field = %s";
				$args[]  = $value;
			}
		}

		return array( implode( ' AND ', $where ), $args );
	}
}
