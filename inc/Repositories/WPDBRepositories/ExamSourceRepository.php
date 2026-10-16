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

	/** Источник открытой записи проведения (не более одного). */
	public function findPublicByEvent( int $eventId ): ?ExamSourceDTO {
		$row = $this->readRow( $this->wpdb->prepare( 'SELECT * FROM %i WHERE event_id = %d AND school_key = %s ORDER BY id ASC LIMIT 1', $this->table, $eventId, ExamSourceDTO::PUBLIC_KEY ) );
		return null !== $row ? ExamSourceDTO::fromArray( $row ) : null;
	}

	/**
	 * Включённые источники открытой записи по всем проведениям: страница выбора класса сама отбирает из них опубликованные и открытые.
	 *
	 * @return ExamSourceDTO[]
	 */
	public function listActivePublic(): array {
		$rows = $this->readRows( $this->wpdb->prepare( 'SELECT * FROM %i WHERE school_key = %s AND is_active = 1 ORDER BY id DESC', $this->table, ExamSourceDTO::PUBLIC_KEY ) );
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

	/** Удаляет источник. Вызывать после {@see isReferenced()} и отзыва ключа. */
	public function delete( int $id ): void {
		$this->write( $this->wpdb->prepare( 'DELETE FROM %i WHERE id = %d', $this->table, $id ) );
	}

	/** На источник ссылаются заявки гостей, участия, сессии гостей или школьные отчёты. */
	public function isReferenced( int $id ): bool {
		return $this->readInt(
			$this->wpdb->prepare(
				'SELECT ( SELECT COUNT(*) FROM %i WHERE source_id = %d )
					+ ( SELECT COUNT(*) FROM %i WHERE source_id = %d )
					+ ( SELECT COUNT(*) FROM %i WHERE source_id = %d )
					+ ( SELECT COUNT(*) FROM %i WHERE recipient_source_id = %d )',
				TableName::ExamGuestApplications->prefixed(),
				$id,
				TableName::ExamParticipations->prefixed(),
				$id,
				TableName::ExamGuestSessions->prefixed(),
				$id,
				TableName::ExamReports->prefixed(),
				$id
			)
		) > 0;
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
