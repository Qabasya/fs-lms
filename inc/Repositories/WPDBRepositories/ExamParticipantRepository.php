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

	/**
	 * Гости, чьи данные пора обезличить: нет `person_id`, ещё не обезличены, все проведения завершены или отменены раньше `$cutoffUtc`,
	 * нет открытых ручных разборов оплаты (заявка `paid_needs_resolution` без строки урегулирования).
	 *
	 * @return int[]
	 */
	public function listGuestIdsDueForAnonymization( string $cutoffUtc, int $limit ): array {
		$participations = TableName::ExamParticipations->prefixed();
		$events         = TableName::ExamEvents->prefixed();
		$applications   = TableName::ExamGuestApplications->prefixed();
		$resolutions    = TableName::ExamManualResolutions->prefixed();

		return $this->readInts( $this->wpdb->prepare(
			"SELECT p.id FROM %i p
			 WHERE p.person_id IS NULL AND p.anonymized_at IS NULL
			   AND EXISTS ( SELECT 1 FROM %i pt WHERE pt.participant_id = p.id )
			   AND NOT EXISTS (
			     SELECT 1 FROM %i pt INNER JOIN %i e ON e.id = pt.event_id
			     WHERE pt.participant_id = p.id
			       AND ( e.status NOT IN ( 'completed', 'cancelled' ) OR COALESCE( e.completed_at, e.cancelled_at, e.updated_at ) >= %s )
			   )
			   AND NOT EXISTS (
			     SELECT 1 FROM %i ga
			     WHERE ga.participant_id = p.id AND ga.state = 'paid_needs_resolution'
			       AND NOT EXISTS ( SELECT 1 FROM %i mr WHERE mr.application_id = ga.id )
			   )
			 ORDER BY p.id ASC LIMIT %d",
			$this->table,
			$participations,
			$participations,
			$events,
			$cutoffUtc,
			$applications,
			$resolutions,
			$limit
		) );
	}

	/**
	 * Обезличивание: идентифицирующие поля очищаются, `school_name` остаётся для агрегатов. Только гость (`person_id IS NULL`) и только один раз.
	 *
	 * @return bool true — участник обезличен этим вызовом.
	 */
	public function anonymize( int $id, string $atUtc ): bool {
		return 1 === $this->write( $this->wpdb->prepare(
			'UPDATE %i SET name_enc = NULL, phone_enc = NULL, messenger_enc = NULL, name_hash = NULL, phone_hash = NULL, anonymized_at = %s, updated_at = %s
			 WHERE id = %d AND person_id IS NULL AND anonymized_at IS NULL',
			$this->table,
			$atUtc,
			$atUtc,
			$id
		) );
	}
}
