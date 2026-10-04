<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\DTO\Exam\ExamOperationKeyDTO;
use Inc\Enums\Settings\TableName;

/** Ключи идемпотентности: повторный запрос с тем же ключом возвращает прежний результат. */
class ExamOperationKeyRepository extends AbstractExamRepository {

	private string $table;

	public function __construct( ?\wpdb $wpdb = null ) {
		parent::__construct( $wpdb );
		$this->table = TableName::ExamOperationKeys->prefixed();
	}

	public function findByKey( string $scope, string $operation, string $requestKey ): ?ExamOperationKeyDTO {
		$row = $this->readRow( $this->wpdb->prepare(
			'SELECT * FROM %i WHERE scope = %s AND operation = %s AND request_key = %s',
			$this->table,
			$scope,
			$operation,
			$requestKey
		) );
		return null !== $row ? ExamOperationKeyDTO::fromArray( $row ) : null;
	}

	/**
	 * Удаляет просроченные ключи (повтор после срока — уже новая операция).
	 *
	 * @return int Сколько строк удалено.
	 */
	public function purgeExpired( string $nowUtc ): int {
		return $this->write( $this->wpdb->prepare( 'DELETE FROM %i WHERE expires_at <= %s', $this->table, $nowUtc ) );
	}

	/**
	 * Запоминает результат операции. Если строка с таким ключом уже была (например, просроченная) —
	 * перезаписывает её: уникальный индекс `(scope, operation, request_key)` не даст вставить вторую.
	 */
	public function remember( string $scope, string $operation, string $requestKey, string $payloadHash, ?string $resultRef, string $expiresAt, string $nowUtc ): void {
		$this->write( $this->wpdb->prepare(
			'INSERT INTO %i ( scope, operation, request_key, payload_hash, result_ref, expires_at, created_at )
			 VALUES ( %s, %s, %s, %s, %s, %s, %s )
			 ON DUPLICATE KEY UPDATE payload_hash = VALUES( payload_hash ), result_ref = VALUES( result_ref ), expires_at = VALUES( expires_at ), created_at = VALUES( created_at )',
			$this->table,
			$scope,
			$operation,
			$requestKey,
			$payloadHash,
			$resultRef ?? '',
			$expiresAt,
			$nowUtc
		) );
	}
}
