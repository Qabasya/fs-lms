<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\DTO\Exam\ExamEventDTO;
use Inc\Enums\Exam\ExamEventStatus;
use Inc\Enums\Exam\ExamSessionStatus;
use Inc\Enums\Settings\TableName;

class ExamEventRepository extends AbstractExamRepository {

	private string $table;

	public function __construct( ?\wpdb $wpdb = null ) {
		parent::__construct( $wpdb );
		$this->table = TableName::ExamEvents->prefixed();
	}

	public function insert( array $data ): int {
		return $this->insertRow( $this->table, $data );
	}

	public function find( int $id ): ?ExamEventDTO {
		$row = $this->readRow( $this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table, $id ) );
		return null !== $row ? ExamEventDTO::fromArray( $row ) : null;
	}

	/** Блокирующее чтение проведения — первый оператор транзакции любой его правки. */
	public function findForUpdate( int $id ): ?ExamEventDTO {
		$row = $this->readRow( $this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d FOR UPDATE', $this->table, $id ) );
		return null !== $row ? ExamEventDTO::fromArray( $row ) : null;
	}

	/**
	 * Версионированное обновление проведения.
	 *
	 * @param array<string, scalar|null> $data
	 *
	 * @return bool false — версия устарела.
	 */
	public function update( int $id, array $data, int $expectedVersion ): bool {
		return $this->updateVersioned( $this->table, $id, $data, $expectedVersion );
	}

	/** @return ExamEventDTO[] Проведения владельца, свежие первыми. */
	public function listByOwner( int $userId ): array {
		$rows = $this->readRows( $this->wpdb->prepare( 'SELECT * FROM %i WHERE owner_user_id = %d ORDER BY created_at DESC', $this->table, $userId ) );
		return array_map( array( ExamEventDTO::class, 'fromArray' ), $rows );
	}

	/** @return ExamEventDTO[] */
	public function findBySubjectKey( string $subjectKey ): array {
		$rows = $this->readRows( $this->wpdb->prepare( 'SELECT * FROM %i WHERE subject_key = %s ORDER BY created_at DESC', $this->table, $subjectKey ) );
		return array_map( array( ExamEventDTO::class, 'fromArray' ), $rows );
	}

	/**
	 * @param string[] $subjectKeys
	 * @param string[] $statuses
	 *
	 * @return ExamEventDTO[]
	 */
	public function findBySubjectsAndStatuses( array $subjectKeys, array $statuses ): array {
		if ( array() === $subjectKeys || array() === $statuses ) {
			return array();
		}

		$subjectPlaceholders = implode( ', ', array_fill( 0, count( $subjectKeys ), '%s' ) );
		$statusPlaceholders  = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		$rows = $this->readRows(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- плейсхолдеры собраны из числа ключей и статусов.
			$this->wpdb->prepare( "SELECT * FROM %i WHERE subject_key IN ( {$subjectPlaceholders} ) AND status IN ( {$statusPlaceholders} ) ORDER BY created_at DESC", $this->table, ...$subjectKeys, ...$statuses )
		);
		return array_map( array( ExamEventDTO::class, 'fromArray' ), $rows );
	}

	/**
	 * @param int[] $ids
	 *
	 * @return ExamEventDTO[]
	 */
	public function findByIds( array $ids ): array {
		if ( array() === $ids ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = $this->readRows(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- плейсхолдеры собраны из числа ID.
			$this->wpdb->prepare( "SELECT * FROM %i WHERE id IN ( {$placeholders} ) ORDER BY created_at DESC", $this->table, ...array_map( 'intval', $ids ) )
		);
		return array_map( array( ExamEventDTO::class, 'fromArray' ), $rows );
	}
	/**
	 * Опубликованные проведения с открытой записью (окно записи не задано или включает «сейчас»).
	 *
	 * @return ExamEventDTO[]
	 */
	public function listOpenForRegistration( string $nowUtc ): array {
		$rows = $this->readRows( $this->wpdb->prepare(
			'SELECT * FROM %i WHERE status = %s AND ( registration_opens_at IS NULL OR registration_opens_at <= %s )
			 AND ( registration_closes_at IS NULL OR registration_closes_at > %s ) ORDER BY id ASC',
			$this->table,
			ExamEventStatus::Published->value,
			$nowUtc,
			$nowUtc
		) );

		return array_map( array( ExamEventDTO::class, 'fromArray' ), $rows );
	}

	/**
	 * Опубликованные проведения, у которых есть неотменённый сеанс и все такие сеансы уже закончились (кандидаты на завершение).
	 * Остальные условия завершения (нет действующих записей и идущих попыток) проверяет сервис под блокировкой.
	 *
	 * @return int[]
	 */
	public function listDueForCompletion( string $nowUtc, int $limit = 100 ): array {
		$sessions = TableName::ExamSessions->prefixed();

		return $this->readInts( $this->wpdb->prepare(
			'SELECT e.id FROM %i e
			 WHERE e.status = %s
			   AND EXISTS ( SELECT 1 FROM %i s WHERE s.event_id = e.id AND s.status <> %s )
			   AND NOT EXISTS ( SELECT 1 FROM %i s2 WHERE s2.event_id = e.id AND s2.status <> %s AND s2.planned_end_at > %s )
			 ORDER BY e.id ASC LIMIT %d',
			$this->table,
			ExamEventStatus::Published->value,
			$sessions,
			ExamSessionStatus::Cancelled->value,
			$sessions,
			ExamSessionStatus::Cancelled->value,
			$nowUtc,
			$limit
		) );
	}

	/**
	 * Удаляет проведения с названием на `$prefix` и всё, что к ним относится: сеансы, участия, записи, заявки, источники и их ключи,
	 * события outbox, ключи идемпотентности, участников без учётной записи. **Только для фикстур стенда** (`wp fs-lms exam stand-clean`):
	 * в работе проведения не удаляются, а отменяются.
	 *
	 * @return int Сколько проведений удалено.
	 */
	public function purgeByTitlePrefix( string $prefix ): int {
		$eventIds = $this->readInts( $this->wpdb->prepare( 'SELECT id FROM %i WHERE title LIKE %s', $this->table, $this->wpdb->esc_like( $prefix ) . '%' ) );
		if ( array() === $eventIds ) {
			return 0;
		}

		$in            = implode( ', ', array_map( 'intval', $eventIds ) );
		$participation = TableName::ExamParticipations->prefixed();
		$registrations = TableName::ExamRegistrations->prefixed();
		$applications  = TableName::ExamGuestApplications->prefixed();
		$sources       = TableName::ExamSources->prefixed();
		$outbox        = TableName::ExamOutbox->prefixed();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- `$in` собран из целых чисел, имена таблиц — из TableName.
		$attempts = TableName::AssessmentAttempts->prefixed();
		$statements = array(
			// Попытки стенда (сценарий start-vs-missed): ответы, сами попытки и события outbox участий.
			'DELETE ans FROM ' . TableName::AssessmentAnswers->prefixed() . " ans INNER JOIN {$attempts} a ON a.id = ans.attempt_id INNER JOIN {$participation} p ON p.id = a.exam_participation_id WHERE p.event_id IN ( {$in} )",
			"DELETE a FROM {$attempts} a INNER JOIN {$participation} p ON p.id = a.exam_participation_id WHERE p.event_id IN ( {$in} )",
			"DELETE o FROM {$outbox} o INNER JOIN {$participation} p ON o.aggregate_type = 'participation' AND o.aggregate_id = p.id WHERE p.event_id IN ( {$in} )",
			"DELETE o FROM {$outbox} o INNER JOIN {$registrations} r ON o.aggregate_type = 'registration' AND o.aggregate_id = r.id INNER JOIN {$participation} p ON p.id = r.participation_id WHERE p.event_id IN ( {$in} )",
			"DELETE FROM {$outbox} WHERE aggregate_type = 'guest_application' AND aggregate_id IN ( SELECT id FROM {$applications} WHERE event_id IN ( {$in} ) )",
			"DELETE FROM {$outbox} WHERE aggregate_type = 'event' AND aggregate_id IN ( {$in} )",
			'DELETE k FROM ' . TableName::ExamOperationKeys->prefixed() . " k INNER JOIN {$participation} p ON k.scope = CONCAT( 'participant:', p.participant_id ) WHERE p.event_id IN ( {$in} )",
			"DELETE r FROM {$registrations} r INNER JOIN {$participation} p ON p.id = r.participation_id WHERE p.event_id IN ( {$in} )",
			'DELETE l FROM ' . TableName::ExamPaymentLinks->prefixed() . " l INNER JOIN {$applications} a ON a.id = l.application_id WHERE a.event_id IN ( {$in} )",
			// Строки ручного урегулирования оплат (этап 8.8): по заявкам и по участиям стенда.
			'DELETE m FROM ' . TableName::ExamManualResolutions->prefixed() . " m INNER JOIN {$applications} a ON a.id = m.application_id WHERE a.event_id IN ( {$in} )",
			'DELETE m FROM ' . TableName::ExamManualResolutions->prefixed() . " m INNER JOIN {$participation} p ON p.id = m.participation_id WHERE p.event_id IN ( {$in} )",
			"DELETE FROM {$applications} WHERE event_id IN ( {$in} )",
			'DELETE t FROM ' . TableName::ExamAccessTokens->prefixed() . " t INNER JOIN {$sources} s ON t.purpose = 'invitation' AND t.target_id = s.id WHERE s.event_id IN ( {$in} )",
			// Гостевые сессии и ключи входа/результата/отчёта участий стенда (сеанс на 50 гостей, этап 13.1.4).
			'DELETE gs FROM ' . TableName::ExamGuestSessions->prefixed() . " gs INNER JOIN {$participation} p ON gs.participation_id = p.id WHERE p.event_id IN ( {$in} )",
			'DELETE t FROM ' . TableName::ExamAccessTokens->prefixed() . " t INNER JOIN {$participation} p ON t.purpose IN ( 'entry', 'result' ) AND t.target_id = p.id WHERE p.event_id IN ( {$in} )",
			'DELETE pt FROM ' . TableName::ExamParticipants->prefixed() . " pt INNER JOIN {$participation} p ON p.participant_id = pt.id WHERE p.event_id IN ( {$in} ) AND pt.person_id IS NULL",
			"DELETE FROM {$participation} WHERE event_id IN ( {$in} )",
			"DELETE FROM {$sources} WHERE event_id IN ( {$in} )",
			'DELETE FROM ' . TableName::ExamSessions->prefixed() . " WHERE event_id IN ( {$in} )",
		);
		// phpcs:enable

		foreach ( $statements as $sql ) {
			$this->write( $sql );
		}

		// Участники, посеянные `stand-seed` без участий (имя школы — префикс стенда), в проведения не попадают и иначе копились бы.
		$this->write( $this->wpdb->prepare(
			'DELETE pt FROM %i pt LEFT JOIN %i p ON p.participant_id = pt.id WHERE pt.person_id IS NULL AND pt.school_name LIKE %s AND p.id IS NULL',
			TableName::ExamParticipants->prefixed(),
			$participation,
			$this->wpdb->esc_like( $prefix ) . '%'
		) );

		return $this->write( $this->wpdb->prepare( "DELETE FROM %i WHERE id IN ( {$in} )", $this->table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
