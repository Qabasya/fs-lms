<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\DTO\Exam\ExamGuestSessionDTO;
use Inc\Enums\Settings\TableName;

/** Сессии гостя по кукам: в базе только хеш куки. */
class ExamGuestSessionRepository extends AbstractExamRepository {

	private string $table;

	public function __construct( ?\wpdb $wpdb = null ) {
		parent::__construct( $wpdb );
		$this->table = TableName::ExamGuestSessions->prefixed();
	}

	/** @param array<string, mixed> $data */
	public function insert( array $data ): int {
		return $this->insertRow( $this->table, $data );
	}

	public function find( int $id ): ?ExamGuestSessionDTO {
		$row = $this->readRow( $this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table, $id ) );
		return null !== $row ? ExamGuestSessionDTO::fromArray( $row ) : null;
	}

	/** Действующая (не отозванная) сессия по хешу куки. */
	public function findByCookieHash( string $cookieHash ): ?ExamGuestSessionDTO {
		$row = $this->readRow( $this->wpdb->prepare( 'SELECT * FROM %i WHERE cookie_hash = %s AND revoked_at IS NULL', $this->table, $cookieHash ) );
		return null !== $row ? ExamGuestSessionDTO::fromArray( $row ) : null;
	}

	/** Отзывает все действующие гостевые сессии приглашения источника (перевыпуск и отзыв ссылки). @return int Число отозванных. */
	public function revokeBySource( int $sourceId, string $nowUtc ): int {
		return $this->write( $this->wpdb->prepare(
			'UPDATE %i SET revoked_at = %s WHERE source_id = %d AND scope = %s AND revoked_at IS NULL',
			$this->table,
			$nowUtc,
			$sourceId,
			'invitation'
		) );
	}

	/**
	 * Отзывает действующие сессии участия данного назначения (`entry` или `result`), кроме одной (последний вход побеждает).
	 *
	 * @return int Число отозванных.
	 */
	public function revokeByParticipation( int $participationId, string $scope, string $nowUtc, int $exceptId = 0 ): int {
		return $this->write( $this->wpdb->prepare(
			'UPDATE %i SET revoked_at = %s WHERE participation_id = %d AND scope = %s AND id <> %d AND revoked_at IS NULL',
			$this->table,
			$nowUtc,
			$participationId,
			$scope,
			$exceptId
		) );
	}

	/** Продлевает действующие сессии участия данного назначения до срока (после старта попытки — до личного дедлайна + время на просмотр). */
	public function extendByParticipation( int $participationId, string $scope, string $expiresAtUtc ): int {
		return $this->write( $this->wpdb->prepare(
			'UPDATE %i SET expires_at = %s WHERE participation_id = %d AND scope = %s AND revoked_at IS NULL AND expires_at < %s',
			$this->table,
			$expiresAtUtc,
			$participationId,
			$scope,
			$expiresAtUtc
		) );
	}

	/** Ограничивает срок действующих сессий участия: после сдачи просмотр результата живёт ограниченное время. */
	public function capByParticipation( int $participationId, string $scope, string $expiresAtUtc ): int {
		return $this->write( $this->wpdb->prepare(
			'UPDATE %i SET expires_at = %s WHERE participation_id = %d AND scope = %s AND revoked_at IS NULL AND expires_at > %s',
			$this->table,
			$expiresAtUtc,
			$participationId,
			$scope,
			$expiresAtUtc
		) );
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
