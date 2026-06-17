<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\DTO\Assessment\AttemptAnswerDTO;
use Inc\Enums\TableName;

class AssessmentAnswerRepository {

	private \wpdb  $wpdb;
	private string $table;

	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb  = $wpdb ?? $GLOBALS['wpdb'];
		$this->table = TableName::AssessmentAnswers->prefixed();
	}

	/**
	 * Вставляет или обновляет ответ на задание внутри попытки.
	 *
	 * @param int   $attemptId
	 * @param int   $taskId
	 * @param array $data  Поля для записи (answer_text, is_correct, score, max_score, …)
	 */
	public function upsert( int $attemptId, int $taskId, array $data ): bool {
		$existing = $this->findByAttemptAndTask( $attemptId, $taskId );

		if ( $existing ) {
			$result = $this->wpdb->update(
				$this->table,
				$data,
				[ 'attempt_id' => $attemptId, 'task_id' => $taskId ]
			);
			return false !== $result;
		}

		$result = $this->wpdb->insert(
			$this->table,
			array_merge( [ 'attempt_id' => $attemptId, 'task_id' => $taskId ], $data )
		);
		return false !== $result;
	}

	public function find( int $id ): ?AttemptAnswerDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d LIMIT 1',
				$this->table,
				$id
			),
			ARRAY_A
		);
		return $row ? AttemptAnswerDTO::fromArray( $row ) : null;
	}

	public function findByAttemptAndTask( int $attemptId, int $taskId ): ?AttemptAnswerDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE attempt_id = %d AND task_id = %d LIMIT 1',
				$this->table,
				$attemptId,
				$taskId
			),
			ARRAY_A
		);
		return $row ? AttemptAnswerDTO::fromArray( $row ) : null;
	}

	/** @return AttemptAnswerDTO[] */
	public function listByAttempt( int $attemptId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE attempt_id = %d ORDER BY id ASC',
				$this->table,
				$attemptId
			),
			ARRAY_A
		);
		return array_map( [ AttemptAnswerDTO::class, 'fromArray' ], $rows ?: [] );
	}

	public function deleteByAttempt( int $attemptId ): bool {
		$result = $this->wpdb->delete( $this->table, [ 'attempt_id' => $attemptId ] );
		return false !== $result;
	}
}
