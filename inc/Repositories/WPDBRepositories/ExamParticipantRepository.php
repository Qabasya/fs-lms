<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\DTO\Exam\ExamParticipantDTO;
use Inc\Enums\Settings\TableName;

class ExamParticipantRepository extends AbstractExamRepository {

	private string $table;

	public function __construct( ?\wpdb $wpdb = null ) {
		parent::__construct( $wpdb );
		$this->table = TableName::ExamParticipants->prefixed();
	}

	public function insert( array $data ): int {
		return $this->insertRow( $this->table, $data );
	}

	public function find( int $id ): ?ExamParticipantDTO {
		return $this->hydrate( $this->readRow( $this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table, $id ) ) );
	}

	/**
	 * Блокирующее чтение участника — точка сериализации одного человека между проведениями
	 * (проверка пересечения по времени). Порядок блокировок: участник → участие → сеансы.
	 * Вызывать только внутри транзакции.
	 */
	public function findForUpdate( int $id ): ?ExamParticipantDTO {
		return $this->hydrate( $this->readRow( $this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d FOR UPDATE', $this->table, $id ) ) );
	}

	public function findByPersonId( int $personId ): ?ExamParticipantDTO {
		return $this->hydrate( $this->readRow( $this->wpdb->prepare( 'SELECT * FROM %i WHERE person_id = %d', $this->table, $personId ) ) );
	}

	/**
	 * Участник-ученик: создаёт при первом обращении. `INSERT IGNORE` по уникальному `person_id`
	 * делает вызов безопасным при одновременных запросах.
	 */
	public function getOrCreateForPerson( int $personId ): int {
		$now = gmdate( 'Y-m-d H:i:s' );
		$this->write( $this->wpdb->prepare(
			'INSERT IGNORE INTO %i ( person_id, created_at, updated_at ) VALUES ( %d, %s, %s )',
			$this->table,
			$personId,
			$now,
			$now
		) );

		$id = $this->readInt( $this->wpdb->prepare( 'SELECT id FROM %i WHERE person_id = %d', $this->table, $personId ) );
		if ( 0 === $id ) {
			throw new \RuntimeException( 'Не удалось получить участника экзамена.' );
		}
		return $id;
	}

	/** @param array<string, mixed>|null $row */
	private function hydrate( ?array $row ): ?ExamParticipantDTO {
		return null !== $row ? ExamParticipantDTO::fromArray( $row ) : null;
	}
}
