<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\DTO\Exam\ExamSourceDTO;
use Inc\Enums\Settings\TableName;

/** Источники гостевой записи (школы, классы) проведения: к источнику привязаны ключ-приглашение и заявки гостей. */
class ExamSourceRepository extends AbstractExamRepository {

	private string $table;

	public function __construct( ?\wpdb $wpdb = null ) {
		parent::__construct( $wpdb );
		$this->table = TableName::ExamSources->prefixed();
	}

	/** @param array<string, mixed> $data */
	public function insert( array $data ): int {
		return $this->insertRow( $this->table, $data );
	}

	public function find( int $id ): ?ExamSourceDTO {
		$row = $this->readRow( $this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table, $id ) );
		return null !== $row ? ExamSourceDTO::fromArray( $row ) : null;
	}

	/** Блокирующее чтение источника — первый оператор транзакции выдачи, перевыпуска и правки. */
	public function findForUpdate( int $id ): ?ExamSourceDTO {
		$row = $this->readRow( $this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d FOR UPDATE', $this->table, $id ) );
		return null !== $row ? ExamSourceDTO::fromArray( $row ) : null;
	}

	/**
	 * Источники проведения для управления (все, включая выключенные), по порядку создания.
	 *
	 * @return ExamSourceDTO[]
	 */
	public function listByEvent( int $eventId ): array {
		$rows = $this->readRows( $this->wpdb->prepare( 'SELECT * FROM %i WHERE event_id = %d ORDER BY id ASC', $this->table, $eventId ) );
		return array_map( array( ExamSourceDTO::class, 'fromArray' ), $rows );
	}

	/**
	 * Версионированное обновление источника.
	 *
	 * @param array<string, scalar|null> $data
	 *
	 * @return bool false — версия устарела.
	 */
	public function update( int $id, array $data, int $expectedVersion ): bool {
		return $this->updateVersioned( $this->table, $id, $data, $expectedVersion );
	}

	/**
	 * Увеличивает поколение ключа источника (выдача и перевыпуск); сессии гостей хранят поколение и сверяют его.
	 * `version` не меняет: поколение — не правка полей источника.
	 *
	 * @return int Новое значение поколения.
	 */
	public function bumpGeneration( int $id ): int {
		$this->write( $this->wpdb->prepare( 'UPDATE %i SET key_generation = key_generation + 1 WHERE id = %d', $this->table, $id ) );
		return $this->readInt( $this->wpdb->prepare( 'SELECT key_generation FROM %i WHERE id = %d', $this->table, $id ) );
	}

	/** Время отзыва ключа: `null` снимает отметку (после перевыпуска). */
	public function setKeyRevokedAt( int $id, ?string $atUtc ): void {
		if ( null === $atUtc ) {
			$this->write( $this->wpdb->prepare( 'UPDATE %i SET key_revoked_at = NULL WHERE id = %d', $this->table, $id ) );
			return;
		}
		$this->write( $this->wpdb->prepare( 'UPDATE %i SET key_revoked_at = %s WHERE id = %d', $this->table, $atUtc, $id ) );
	}
}
