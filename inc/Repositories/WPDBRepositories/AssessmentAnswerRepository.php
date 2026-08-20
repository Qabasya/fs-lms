<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\DTO\Assessment\AttemptAnswerDTO;
use Inc\Enums\Settings\TableName;

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
	 * Одним запросом, а не «прочитать → решить → записать»: автосохранение шлёт
	 * ответ по вводу, по уходу из поля и по кнопке, и два таких запроса приходят
	 * почти одновременно. При проверке существования на стороне PHP оба видели
	 * пустоту и делали по INSERT — в таблице оказывались две строки на одну пару
	 * (attempt_id, task_id), а дальше чтение брало произвольную из них: ответ
	 * «то сохранялся, то нет». Уникальный ключ `attempt_task (attempt_id, task_id)`
	 * на таблицу ставит `Migration_1_0_0` (секция `assessment_answers`).
	 *
	 * @param int   $attemptId Попытка
	 * @param int   $taskId    Задание
	 * @param array $data      Поля для записи (answer_text, is_correct, score, max_score, …)
	 */
	public function upsert( int $attemptId, int $taskId, array $data ): bool {
		$row = array_merge( [ 'attempt_id' => $attemptId, 'task_id' => $taskId ], $data );

		$columns      = array();
		$placeholders = array();
		$values       = array();

		foreach ( $row as $column => $value ) {
			$columns[] = '`' . $column . '`';
			// null через %s превратился бы в пустую строку — «не оценено» стало бы
			// нулём, поэтому NULL кладём литералом.
			if ( null === $value ) {
				$placeholders[] = 'NULL';
				continue;
			}
			$placeholders[] = '%s';
			$values[]       = $value;
		}

		// Ключевые колонки в UPDATE не попадают: они и так совпали. Данных, кроме
		// ключей, не бывает (все вызовы пишут хотя бы одно поле), но пустой список
		// сделал бы запрос синтаксически неверным — подстраховываемся no-op'ом.
		$updates = array_map(
			static fn( string $column ): string => "`$column` = VALUES(`$column`)",
			array_keys( $data )
		);
		$onDuplicate = $updates ? implode( ', ', $updates ) : '`attempt_id` = VALUES(`attempt_id`)';

		$sql = 'INSERT INTO `' . $this->table . '` (' . implode( ', ', $columns ) . ')'
			. ' VALUES (' . implode( ', ', $placeholders ) . ')'
			. ' ON DUPLICATE KEY UPDATE ' . $onDuplicate;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->wpdb->query( $values ? $this->wpdb->prepare( $sql, $values ) : $sql );

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

	/**
	 * Есть ли у попытки хотя бы один ответ, ещё не оценённый (`is_correct IS NULL`) —
	 * критерий «требует ручной оценки» для вкладки «Работы» (D3, .docs/Tasks.md).
	 */
	public function hasPendingAnswers( int $attemptId ): bool {
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT EXISTS(SELECT 1 FROM %i WHERE attempt_id = %d AND is_correct IS NULL)',
				$this->table,
				$attemptId
			)
		) > 0;
	}

	public function deleteByAttempt( int $attemptId ): bool {
		$result = $this->wpdb->delete( $this->table, [ 'attempt_id' => $attemptId ] );
		return false !== $result;
	}
}
