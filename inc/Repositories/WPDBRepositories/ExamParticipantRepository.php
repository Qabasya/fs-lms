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
	/**
	 * Гости с их проведениями и сеансом: строка на участие (гость без участия — одна строка с пустыми полями проведения).
	 * Сеанс — по действующей записи, а если её нет, по последней. Порядок и страницы — на вызывающем (ФИО зашифровано).
	 *
	 * @param array{event_id?: int, session_id?: int, school?: string} $filters Фильтры.
	 * @return list<array<string, mixed>>
	 */
	public function listGuestRows( array $filters = array() ): array {
		$where  = array( 'p.person_id IS NULL' );
		$params = array();
		if ( ! empty( $filters['event_id'] ) ) {
			$where[]  = 'pn.event_id = %d';
			$params[] = (int) $filters['event_id'];
		}
		if ( ! empty( $filters['session_id'] ) ) {
			$where[]  = 's.id = %d';
			$params[] = (int) $filters['session_id'];
		}
		if ( ! empty( $filters['school'] ) ) {
			$where[]  = 'p.school_name = %s';
			$params[] = (string) $filters['school'];
		}

		$sql = 'SELECT p.*, pn.id AS participation_id, pn.event_id, ev.title AS event_title, r.id AS registration_id, r.status AS registration_status,
				s.id AS session_id, s.scheduled_at, s.planned_end_at, s.status AS session_status
			FROM %i p
			LEFT JOIN %i pn ON pn.participant_id = p.id
			LEFT JOIN %i ev ON ev.id = pn.event_id
			LEFT JOIN %i r ON r.id = COALESCE( pn.active_registration_id, ( SELECT MAX( r2.id ) FROM %i r2 WHERE r2.participation_id = pn.id ) )
			LEFT JOIN %i s ON s.id = r.session_id
			WHERE ' . implode( ' AND ', $where ) . '
			ORDER BY p.created_at DESC, p.id DESC, pn.id DESC';

		return $this->readRows(
			$this->wpdb->prepare(
				$sql,
				array_merge(
					array(
						$this->table,
						TableName::ExamParticipations->prefixed(),
						TableName::ExamEvents->prefixed(),
						TableName::ExamRegistrations->prefixed(),
						TableName::ExamRegistrations->prefixed(),
						TableName::ExamSessions->prefixed(),
					),
					$params
				)
			)
		);
	}

	/**
	 * Школы гостей — словарь фильтра.
	 *
	 * @return string[]
	 */
	public function guestSchools(): array {
		$rows = $this->readRows(
			$this->wpdb->prepare( 'SELECT DISTINCT school_name FROM %i WHERE person_id IS NULL AND school_name IS NOT NULL AND school_name <> %s ORDER BY school_name', $this->table, '' )
		);

		return array_map( static fn ( array $row ): string => (string) $row['school_name'], $rows );
	}

	/**
	 * Проведения и сеансы, на которые записаны гости, — словарь фильтров.
	 *
	 * @return list<array<string, mixed>> event_id, event_title, session_id, scheduled_at
	 */
	public function guestSessionOptions(): array {
		return $this->readRows(
			$this->wpdb->prepare(
				'SELECT DISTINCT ev.id AS event_id, ev.title AS event_title, s.id AS session_id, s.scheduled_at
				FROM %i p
				JOIN %i pn ON pn.participant_id = p.id
				JOIN %i ev ON ev.id = pn.event_id
				LEFT JOIN %i r ON r.participation_id = pn.id
				LEFT JOIN %i s ON s.id = r.session_id
				WHERE p.person_id IS NULL
				ORDER BY ev.id DESC, s.scheduled_at',
				$this->table,
				TableName::ExamParticipations->prefixed(),
				TableName::ExamEvents->prefixed(),
				TableName::ExamRegistrations->prefixed(),
				TableName::ExamSessions->prefixed()
			)
		);
	}

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
