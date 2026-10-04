<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\DTO\Exam\ExamAccessTokenDTO;
use Inc\Enums\Exam\ExamTokenPurpose;
use Inc\Enums\Settings\TableName;

/** Ключи доступа (приглашение, вход, результат, отчёт, оплата): в базе только хеш ключа. */
class ExamAccessTokenRepository extends AbstractExamRepository {

	private string $table;

	public function __construct( ?\wpdb $wpdb = null ) {
		parent::__construct( $wpdb );
		$this->table = TableName::ExamAccessTokens->prefixed();
	}

	/** @param array<string, mixed> $data */
	public function insert( array $data ): int {
		return $this->insertRow( $this->table, $data );
	}

	public function find( int $id ): ?ExamAccessTokenDTO {
		$row = $this->readRow( $this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table, $id ) );
		return null !== $row ? ExamAccessTokenDTO::fromArray( $row ) : null;
	}

	/** Токен по хешу ключа (любой — действующий, отозванный, истёкший: решает сервис). */
	public function findByHash( string $tokenHash ): ?ExamAccessTokenDTO {
		$row = $this->readRow( $this->wpdb->prepare( 'SELECT * FROM %i WHERE token_hash = %s', $this->table, $tokenHash ) );
		return null !== $row ? ExamAccessTokenDTO::fromArray( $row ) : null;
	}

	/** Действующий (не отозванный) ключ цели; у цели он не бывает больше одного — выпуск отзывает прежний. */
	public function findActive( ExamTokenPurpose $purpose, int $targetId ): ?ExamAccessTokenDTO {
		$row = $this->readRow( $this->wpdb->prepare(
			'SELECT * FROM %i WHERE purpose = %s AND target_id = %d AND revoked_at IS NULL ORDER BY id DESC LIMIT 1',
			$this->table,
			$purpose->value,
			$targetId
		) );
		return null !== $row ? ExamAccessTokenDTO::fromArray( $row ) : null;
	}

	/** Наибольшее поколение ключей цели (0 — ключей не было). */
	public function maxGeneration( ExamTokenPurpose $purpose, int $targetId ): int {
		return $this->readInt( $this->wpdb->prepare(
			'SELECT COALESCE( MAX( generation ), 0 ) FROM %i WHERE purpose = %s AND target_id = %d',
			$this->table,
			$purpose->value,
			$targetId
		) );
	}

	/**
	 * Отзывает все действующие ключи цели.
	 *
	 * @return int Сколько ключей отозвано.
	 */
	public function revokeByTarget( ExamTokenPurpose $purpose, int $targetId, string $atUtc ): int {
		return $this->write( $this->wpdb->prepare(
			'UPDATE %i SET revoked_at = %s WHERE purpose = %s AND target_id = %d AND revoked_at IS NULL',
			$this->table,
			$atUtc,
			$purpose->value,
			$targetId
		) );
	}

	/** Ручная отметка «Ссылка передана»; повторная отметка ничего не меняет. */
	public function markPassed( int $id, int $userId, string $atUtc ): void {
		$this->write( $this->wpdb->prepare(
			'UPDATE %i SET passed_at = %s, passed_by_user_id = %d WHERE id = %d AND passed_at IS NULL',
			$this->table,
			$atUtc,
			$userId,
			$id
		) );
	}
}
