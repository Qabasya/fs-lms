<?php
/**
 * Полное удаление гостя экзамена со всеми его заявками, записями и следами (тестовые заявки).
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\Enums\Settings\TableName;

/**
 * Удаляет строки гостя из таблиц экзаменов одним методом {@see purge()}; вызывается только из транзакции сервиса удаления.
 * Условия, при которых удалять нельзя (попытки, оплаты, отчёты, ручные разборы), проверяет {@see blocker()}.
 */
class ExamGuestPurgeRepository extends AbstractExamRepository {

	/** Причина, по которой гостя удалять нельзя, либо null. Попытка, оплата, ручной разбор и отчёт — вещи с последствиями вне гостя. */
	public function blocker( int $participantId ): ?string {
		$participations = $this->participationIds( $participantId );
		$applications   = $this->applicationIds( $participantId );

		if ( array() !== $participations && $this->count( TableName::AssessmentAttempts, 'exam_participation_id', $participations ) > 0 ) {
			return 'Гость уже начинал работу: удалить можно только заявку без попыток.';
		}
		if ( array() !== $applications && $this->count( TableName::ExamPaymentLinks, 'application_id', $applications ) > 0 ) {
			return 'По заявке есть заказ оплаты: такую заявку удалять нельзя.';
		}
		if ( ( array() !== $applications && $this->count( TableName::ExamManualResolutions, 'application_id', $applications ) > 0 )
			|| ( array() !== $participations && $this->count( TableName::ExamManualResolutions, 'participation_id', $participations ) > 0 ) ) {
			return 'По заявке есть ручное урегулирование оплаты: удалять нельзя.';
		}
		if ( array() !== $participations && $this->count( TableName::ExamReportMembers, 'participation_id', $participations ) > 0 ) {
			return 'Гость включён в школьный отчёт: сначала уберите его из отчёта.';
		}

		return null;
	}

	/**
	 * Заявки гостя с удерживаемым местом: после удаления место возвращается сеансу.
	 *
	 * @return list<array<string, mixed>> `id`, `session_id`
	 */
	public function heldApplications( int $participantId ): array {
		return $this->readRows(
			$this->wpdb->prepare( 'SELECT id, session_id FROM %i WHERE participant_id = %d AND is_held = 1', TableName::ExamGuestApplications->prefixed(), $participantId )
		);
	}

	/**
	 * Удаляет гостя целиком: ключи и сессии, записи, участия, заявки, согласия, события очереди, ключи повтора, строки журнала «Экзамены»
	 * и самого участника. Журнал находится по записям и по ФИО внутри проведений гостя (у части строк, например «допущен», записи нет).
	 *
	 * @param string|null $name ФИО гостя в том виде, как оно пишется в журнале; null — искать только по записям.
	 */
	public function purge( int $participantId, ?string $name ): void {
		$participations = $this->participationIds( $participantId );
		$applications   = $this->applicationIds( $participantId );
		$registrations  = array() !== $participations ? $this->ids( TableName::ExamRegistrations, 'participation_id', $participations ) : array();
		$events         = array() !== $participations ? $this->column( TableName::ExamParticipations, 'event_id', 'id', $participations ) : array();
		$consents       = $this->consentIds( $participantId );

		if ( array() !== $participations ) {
			$this->deleteIn( TableName::ExamGuestSessions, 'participation_id', $participations );
			$this->deleteTokens( array( 'entry', 'result' ), $participations );
			$this->deleteOutbox( 'participation', $participations );
		}
		if ( array() !== $applications ) {
			$this->deleteTokens( array( 'payment' ), $applications );
			$this->deleteOutbox( 'application', $applications );
		}
		if ( array() !== $registrations ) {
			$this->deleteIn( TableName::ExamLog, 'registration_id', $registrations );
			$this->deleteOutbox( 'registration', $registrations );
		}
		if ( null !== $name && '' !== $name && array() !== $events ) {
			$this->write(
				$this->wpdb->prepare( 'DELETE FROM %i WHERE subject_label = %s AND event_id IN ( ' . $this->list( $events ) . ' )', TableName::ExamLog->prefixed(), $name )
			);
		}

		if ( array() !== $participations ) {
			$this->deleteIn( TableName::ExamRegistrations, 'participation_id', $participations );
		}
		// Участия и заявки ссылаются друг на друга: сначала заявки, затем участия.
		$this->write( $this->wpdb->prepare( 'DELETE FROM %i WHERE participant_id = %d', TableName::ExamGuestApplications->prefixed(), $participantId ) );
		$this->write( $this->wpdb->prepare( 'DELETE FROM %i WHERE participant_id = %d', TableName::ExamParticipations->prefixed(), $participantId ) );
		$this->write( $this->wpdb->prepare( 'DELETE FROM %i WHERE scope = %s', TableName::ExamOperationKeys->prefixed(), 'participant:' . $participantId ) );
		if ( array() !== $consents ) {
			$this->deleteIn( TableName::Consents, 'id', $consents );
		}
		$this->write( $this->wpdb->prepare( 'DELETE FROM %i WHERE id = %d AND person_id IS NULL', TableName::ExamParticipants->prefixed(), $participantId ) );
	}

	/** @return int[] */
	private function participationIds( int $participantId ): array {
		return $this->readInts( $this->wpdb->prepare( 'SELECT id FROM %i WHERE participant_id = %d', TableName::ExamParticipations->prefixed(), $participantId ) );
	}

	/** @return int[] */
	private function applicationIds( int $participantId ): array {
		return $this->readInts( $this->wpdb->prepare( 'SELECT id FROM %i WHERE participant_id = %d', TableName::ExamGuestApplications->prefixed(), $participantId ) );
	}

	/**
	 * Согласия гостя: идентификаторы из `consent_refs` его участий и заявок (телефона и ФИО в них нет, но есть IP и браузер).
	 *
	 * @return int[]
	 */
	private function consentIds( int $participantId ): array {
		$rows = array_merge(
			$this->readRows( $this->wpdb->prepare( 'SELECT consent_refs FROM %i WHERE participant_id = %d', TableName::ExamParticipations->prefixed(), $participantId ) ),
			$this->readRows( $this->wpdb->prepare( 'SELECT consent_refs FROM %i WHERE participant_id = %d', TableName::ExamGuestApplications->prefixed(), $participantId ) )
		);
		$ids  = array();
		foreach ( $rows as $row ) {
			$refs = isset( $row['consent_refs'] ) ? json_decode( (string) $row['consent_refs'], true ) : null;
			foreach ( is_array( $refs ) ? $refs : array() as $id ) {
				if ( (int) $id > 0 ) {
					$ids[] = (int) $id;
				}
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * @param int[] $ids
	 * @return int[]
	 */
	private function ids( TableName $table, string $column, array $ids ): array {
		return $this->readInts( $this->wpdb->prepare( 'SELECT id FROM %i WHERE %i IN ( ' . $this->list( $ids ) . ' )', $table->prefixed(), $column ) );
	}

	/**
	 * @param int[] $ids
	 * @return int[]
	 */
	private function column( TableName $table, string $select, string $by, array $ids ): array {
		return array_values( array_unique( $this->readInts( $this->wpdb->prepare( 'SELECT %i FROM %i WHERE %i IN ( ' . $this->list( $ids ) . ' )', $select, $table->prefixed(), $by ) ) ) );
	}

	/** @param int[] $ids */
	private function count( TableName $table, string $column, array $ids ): int {
		return $this->readInt( $this->wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE %i IN ( ' . $this->list( $ids ) . ' )', $table->prefixed(), $column ) );
	}

	/** @param int[] $ids */
	private function deleteIn( TableName $table, string $column, array $ids ): void {
		$this->write( $this->wpdb->prepare( 'DELETE FROM %i WHERE %i IN ( ' . $this->list( $ids ) . ' )', $table->prefixed(), $column ) );
	}

	/**
	 * @param string[] $purposes Значения {@see \Inc\Enums\Exam\ExamTokenPurpose}
	 * @param int[]    $targets  Цели ключей (участие или заявка — по назначению)
	 */
	private function deleteTokens( array $purposes, array $targets ): void {
		foreach ( $purposes as $purpose ) {
			$this->write(
				$this->wpdb->prepare( 'DELETE FROM %i WHERE purpose = %s AND target_id IN ( ' . $this->list( $targets ) . ' )', TableName::ExamAccessTokens->prefixed(), $purpose )
			);
		}
	}

	/** @param int[] $ids */
	private function deleteOutbox( string $aggregateType, array $ids ): void {
		$this->write(
			$this->wpdb->prepare( 'DELETE FROM %i WHERE aggregate_type = %s AND aggregate_id IN ( ' . $this->list( $ids ) . ' )', TableName::ExamOutbox->prefixed(), $aggregateType )
		);
	}

	/** Список целых для `IN (...)`: значения приведены к int, подстановка безопасна. @param int[] $ids */
	private function list( array $ids ): string {
		return implode( ',', array_map( 'intval', $ids ) );
	}
}
