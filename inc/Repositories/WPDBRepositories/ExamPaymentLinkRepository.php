<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\DTO\Exam\ExamPaymentLinkDTO;
use Inc\Enums\Settings\TableName;

/** Связь заявки гостя с заказом WooCommerce. */
class ExamPaymentLinkRepository extends AbstractExamRepository {

	private string $table;

	public function __construct( ?\wpdb $wpdb = null ) {
		parent::__construct( $wpdb );
		$this->table = TableName::ExamPaymentLinks->prefixed();
	}

	/** @param array<string, mixed> $data */
	public function insert( array $data ): int {
		return $this->insertRow( $this->table, $data );
	}

	public function find( int $id ): ?ExamPaymentLinkDTO {
		$row = $this->readRow( $this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table, $id ) );
		return null !== $row ? ExamPaymentLinkDTO::fromArray( $row ) : null;
	}

	/** Связь по позиции заказа. */
	public function findByWcOrderItem( int $wcOrderItemId ): ?ExamPaymentLinkDTO {
		$row = $this->readRow( $this->wpdb->prepare( 'SELECT * FROM %i WHERE wc_order_item_id = %d', $this->table, $wcOrderItemId ) );
		return null !== $row ? ExamPaymentLinkDTO::fromArray( $row ) : null;
	}

	/**
	 * Связи заявки.
	 *
	 * @return ExamPaymentLinkDTO[]
	 */
	public function findByApplication( int $applicationId ): array {
		$rows = $this->readRows( $this->wpdb->prepare( 'SELECT * FROM %i WHERE application_id = %d', $this->table, $applicationId ) );
		return array_map( array( ExamPaymentLinkDTO::class, 'fromArray' ), $rows );
	}

	/** @return ExamPaymentLinkDTO[] Связи заказа (по одной на экзаменную позицию). */
	public function listByOrder( int $orderId ): array {
		$rows = $this->readRows( $this->wpdb->prepare( 'SELECT * FROM %i WHERE wc_order_id = %d ORDER BY id ASC', $this->table, $orderId ) );
		return array_map( array( ExamPaymentLinkDTO::class, 'fromArray' ), $rows );
	}

	/**
	 * Неподтверждённые связи, не сверявшиеся с `$olderThanUtc` (для минутной сверки).
	 *
	 * @return ExamPaymentLinkDTO[]
	 */
	public function listPendingForReconcile( string $olderThanUtc, int $limit = 50 ): array {
		$rows = $this->readRows( $this->wpdb->prepare(
			'SELECT * FROM %i WHERE payment_state = %s AND created_at <= %s AND ( last_reconciled_at IS NULL OR last_reconciled_at <= %s ) ORDER BY id ASC LIMIT %d',
			$this->table,
			'pending',
			$olderThanUtc,
			$olderThanUtc,
			$limit
		) );
		return array_map( array( ExamPaymentLinkDTO::class, 'fromArray' ), $rows );
	}

	/**
	 * @param array<string, mixed> $data
	 *
	 * @return int Число затронутых строк.
	 */
	public function update( int $id, array $data ): int {
		return $this->updateRow( $this->table, $data, array( 'id' => $id ) );
	}
}
