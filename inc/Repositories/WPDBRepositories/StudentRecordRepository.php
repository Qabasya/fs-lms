<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\DTO\Enrollment\StudentRecordDTO;
use Inc\DTO\Enrollment\StudentRecordInputDTO;
use Inc\Enums\Enrollment\EnrollmentStatus;
use Inc\Enums\Settings\TableName;

/**
 * Class StudentRecordRepository
 *
 * Репозиторий для работы с записями студентов (StudentRecord).
 *
 * @package Inc\Repositories\WPDBRepositories
 *
 * ### Основные обязанности:
 *
 * 1. **CRUD-операции** — создание, чтение, обновление записей студентов.
 * 2. **Поиск по различным критериям** — по студенту, родителю, группе, статусу.
 * 3. **Удаление групп** — массовое удаление записей группы с возвратом ID студентов и родителей.
 * 4. **Отчисление** — обновление статуса на Expelled с фиксацией причины.
 *
 * ### Архитектурная роль:
 *
 * Инкапсулирует вызовы $wpdb для прямых SQL-запросов.
 * Использует DTO StudentRecordDTO для типобезопасной передачи данных.
 * Таблица содержит информацию о зачислении студента: группа, период, статус,
 * а также ссылки на родителя и данные договора/приказа.
 */
class StudentRecordRepository {

	private \wpdb $wpdb;
	private string $table;

	/**
	 * Конструктор репозитория.
	 *
	 * @param \wpdb|null $wpdb Глобальный объект базы данных WordPress
	 */
	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb  = $wpdb ?? $GLOBALS['wpdb'];
		$this->table = TableName::StudentRecords->prefixed();
	}

	/**
	 * Находит запись студента по ID.
	 *
	 * @param int $id ID записи
	 *
	 * @return StudentRecordDTO|null
	 */
	public function find( int $id ): ?StudentRecordDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', $this->table, $id ),
			ARRAY_A
		);

		return $row ? StudentRecordDTO::fromArray( $row ) : null;
	}

	/**
	 * Находит все записи студента (включая архивные).
	 *
	 * @param int $studentPersonId ID студента
	 *
	 * @return StudentRecordDTO[]
	 */
	public function findByStudent( int $studentPersonId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE student_person_id = %d ORDER BY enrolled_at DESC',
				$this->table,
				$studentPersonId
			),
			ARRAY_A
		);

		return array_map( fn( array $r ) => StudentRecordDTO::fromArray( $r ), $rows ?: array() );
	}

	/**
	 * Находит активные записи студента (статус Active).
	 *
	 * @param int $studentPersonId ID студента
	 *
	 * @return StudentRecordDTO[]
	 */
	public function findActiveByStudent( int $studentPersonId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE student_person_id = %d AND status = %s ORDER BY enrolled_at DESC',
				$this->table,
				$studentPersonId,
				EnrollmentStatus::Active->value
			),
			ARRAY_A
		);

		return array_map( fn( array $r ) => StudentRecordDTO::fromArray( $r ), $rows ?: array() );
	}

	/**
	 * Находит первую (последнюю) активную запись студента.
	 *
	 * @param int $studentPersonId ID студента
	 *
	 * @return StudentRecordDTO|null
	 */
	public function findActiveByStudentFirst( int $studentPersonId ): ?StudentRecordDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE student_person_id = %d AND status = %s ORDER BY enrolled_at DESC LIMIT 1',
				$this->table,
				$studentPersonId,
				EnrollmentStatus::Active->value
			),
			ARRAY_A
		);

		return $row ? StudentRecordDTO::fromArray( $row ) : null;
	}

	/**
	 * Находит активные записи студентов в указанной группе.
	 *
	 * @param int $groupId ID группы
	 *
	 * @return StudentRecordDTO[]
	 */
	public function findActiveByGroupId( int $groupId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE group_id = %d AND status = %s ORDER BY enrolled_at DESC',
				$this->table,
				$groupId,
				EnrollmentStatus::Active->value
			),
			ARRAY_A
		);

		return array_map( fn( array $r ) => StudentRecordDTO::fromArray( $r ), $rows ?: array() );
	}

	/**
	 * Находит активные записи, где указанный пользователь является родителем.
	 *
	 * @param int $parentPersonId ID родителя
	 *
	 * @return StudentRecordDTO[]
	 */
	public function findActiveByParent( int $parentPersonId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE parent_person_id = %d AND status = %s ORDER BY enrolled_at DESC',
				$this->table,
				$parentPersonId,
				EnrollmentStatus::Active->value
			),
			ARRAY_A
		);

		return array_map( fn( array $r ) => StudentRecordDTO::fromArray( $r ), $rows ?: array() );
	}

	/**
	 * Проверяет, есть ли у студента какие-либо записи (любой статус).
	 *
	 * @param int $studentPersonId ID студента
	 *
	 * @return bool
	 */
	public function hasAnyRecord( int $studentPersonId ): bool {
		return 0 < (int) $this->wpdb->get_var(
				$this->wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE student_person_id = %d',
					$this->table,
					$studentPersonId
				)
			);
	}

	/**
	 * Проверяет, есть ли у родителя какие-либо записи (любой статус).
	 *
	 * @param int $parentPersonId ID родителя
	 *
	 * @return bool
	 */
	public function hasAnyRecordForParent( int $parentPersonId ): bool {
		return 0 < (int) $this->wpdb->get_var(
				$this->wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE parent_person_id = %d',
					$this->table,
					$parentPersonId
				)
			);
	}

	/**
	 * Находит все записи родителя (любой статус).
	 *
	 * @param int $parentPersonId ID родителя
	 *
	 * @return StudentRecordDTO[]
	 */
	public function findAllByParent( int $parentPersonId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE parent_person_id = %d ORDER BY enrolled_at DESC',
				$this->table,
				$parentPersonId
			),
			ARRAY_A
		);
		return array_map( fn( array $r ) => StudentRecordDTO::fromArray( $r ), $rows ?: array() );
	}

	/**
	 * Находит все записи группы (любой статус).
	 *
	 * @param int $groupId ID группы
	 *
	 * @return StudentRecordDTO[]
	 */
	public function findAllByGroup( int $groupId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE group_id = %d ORDER BY enrolled_at DESC',
				$this->table,
				$groupId
			),
			ARRAY_A
		);
		return array_map( fn( array $r ) => StudentRecordDTO::fromArray( $r ), $rows ?: array() );
	}

	/**
	 * Удаляет все записи группы и возвращает уникальные IDs затронутых учеников и родителей.
	 * Используется при удалении группы.
	 *
	 * @param int $groupId ID группы
	 *
	 * @return array{ students: int[], parents: int[] }
	 */
	public function deleteAllByGroupAndCollect( int $groupId ): array {
		// Получаем список студентов и родителей в группе
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT student_person_id, parent_person_id FROM %i WHERE group_id = %d',
				$this->table,
				$groupId
			),
			ARRAY_A
		);

		$studentIds = array_values( array_unique( array_column( $rows ?: array(), 'student_person_id' ) ) );
		$parentIds  = array_values( array_unique( array_column( $rows ?: array(), 'parent_person_id' ) ) );

		// Удаляем все записи группы
		if ( ! empty( $rows ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$this->wpdb->query(
				$this->wpdb->prepare( 'DELETE FROM %i WHERE group_id = %d', $this->table, $groupId )
			);
		}

		return array(
			'students' => array_map( 'intval', $studentIds ),
			'parents'  => array_map( 'intval', $parentIds ),
		);
	}

	/**
	 * Удаляет все записи ученика (любой статус, любая группа).
	 *
	 * @param int $studentPersonId ID студента
	 *
	 * @return int Количество удалённых записей
	 */
	public function deleteAllByStudent( int $studentPersonId ): int {
		return (int) $this->wpdb->query(
			$this->wpdb->prepare( 'DELETE FROM %i WHERE student_person_id = %d', $this->table, $studentPersonId )
		);
	}

	/**
	 * Количество уникальных учеников в группе (для UI-предупреждения перед удалением).
	 *
	 * @param int $groupId ID группы
	 *
	 * @return int
	 */
	public function countUniqueStudentsByGroup( int $groupId ): int {
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(DISTINCT student_person_id) FROM %i WHERE group_id = %d',
				$this->table,
				$groupId
			)
		);
	}

	public function countActiveByGroup( int $groupId ): int {
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE group_id = %d AND status = 'active'",
				$this->table,
				$groupId
			)
		);
	}

	/**
	 * Проверяет наличие активной записи студента в группе.
	 *
	 * @param int $studentPersonId ID студента
	 * @param int $groupId         ID группы
	 *
	 * @return bool
	 */
	public function existsActive( int $studentPersonId, int $groupId ): bool {
		return 0 < (int) $this->wpdb->get_var(
				$this->wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE student_person_id = %d AND group_id = %d AND status = %s',
					$this->table,
					$studentPersonId,
					$groupId,
					EnrollmentStatus::Active->value
				)
			);
	}

	/**
	 * Проверяет наличие записи студента в группе с указанным номером договора.
	 *
	 * Дедупликация при импорте: повторная загрузка того же файла не должна
	 * создавать дубль (проверка по любому статусу записи).
	 *
	 * @param int    $studentPersonId ID студента
	 * @param int    $groupId         ID группы
	 * @param string $contractNo      Номер договора
	 *
	 * @return bool
	 */
	public function existsByContract( int $studentPersonId, int $groupId, string $contractNo ): bool {
		return 0 < (int) $this->wpdb->get_var(
				$this->wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE student_person_id = %d AND group_id = %d AND contract_no = %s',
					$this->table,
					$studentPersonId,
					$groupId,
					$contractNo
				)
			);
	}

	/**
	 * Создаёт новую запись студента.
	 *
	 * @param StudentRecordInputDTO $dto DTO с полями для вставки
	 *
	 * @return int ID созданной записи
	 */
	public function create( StudentRecordInputDTO $dto ): int {
		$this->wpdb->insert( $this->table, $dto->toArray() );
		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Обновляет существующую запись студента.
	 *
	 * @param int   $id   ID записи
	 * @param array $data Массив обновляемых полей
	 *
	 * @return bool
	 */
	public function update( int $id, array $data ): bool {
		return false !== $this->wpdb->update( $this->table, $data, array( 'id' => $id ) );
	}

	/**
	 * Переводит запись студента в статус Expelled (отчислен).
	 *
	 * @param int         $id         ID записи
	 * @param string      $expelledAt Дата отчисления
	 * @param int         $userId     ID пользователя, выполнившего отчисление
	 * @param string|null $reason     Причина отчисления
	 *
	 * @return bool
	 */
	public function setExpelled( int $id, string $expelledAt, int $userId, ?string $reason ): bool {
		return $this->update( $id, array(
			'status'              => EnrollmentStatus::Expelled->value,
			'expelled_at'         => $expelledAt,
			'expelled_by_user_id' => $userId,
			'expel_reason'        => $reason,
			'updated_at'          => current_time( 'mysql', true ),
		) );
	}

	/**
	 * Получает список записей с фильтрацией и пагинацией.
	 *
	 * @param array $filters Массив фильтров (status, student_person_id)
	 * @param int   $page    Номер страницы
	 * @param int   $perPage Количество записей на странице
	 *
	 * @return StudentRecordDTO[]
	 */
	public function list( array $filters = array(), int $page = 1, int $perPage = 20 ): array {
		$offset = ( max( 1, $page ) - 1 ) * $perPage;
		[ $where, $args ] = $this->buildWhereClause( $filters );
		$args[] = $perPage;
		$args[] = $offset;

		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM %i {$where} ORDER BY enrolled_at DESC LIMIT %d OFFSET %d",
				...$args
			),
			ARRAY_A
		);

		return array_map( fn( array $r ) => StudentRecordDTO::fromArray( $r ), $rows ?: array() );
	}

	/**
	 * Получает количество записей по фильтрам.
	 *
	 * @param array $filters Массив фильтров
	 *
	 * @return int
	 */
	public function count( array $filters = array() ): int {
		[ $where, $args ] = $this->buildWhereClause( $filters );

		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare( "SELECT COUNT(*) FROM %i {$where}", ...$args )
		);
	}

	/**
	 * Возвращает уникальные student_person_id с пагинацией, упорядоченные по дате последнего зачисления.
	 *
	 * @param array $filters  Массив фильтров
	 * @param int   $page     Номер страницы
	 * @param int   $perPage  Количество записей на странице
	 *
	 * @return int[]
	 */
	public function listDistinctStudentIds( array $filters = array(), int $page = 1, int $perPage = 20 ): array {
		$offset            = ( max( 1, $page ) - 1 ) * $perPage;
		[ $where, $args ]  = $this->buildWhereClause( $filters );
		$args[]            = $perPage;
		$args[]            = $offset;

		$rows = $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT student_person_id FROM %i {$where} GROUP BY student_person_id ORDER BY MAX(enrolled_at) DESC LIMIT %d OFFSET %d",
				...$args
			)
		);

		return array_map( 'intval', $rows ?: array() );
	}

	/**
	 * Все уникальные person_id активных зачислений (status=active), без пагинации.
	 *
	 * @return int[]
	 */
	public function allActiveStudentPersonIds(): array {
		$rows = $this->wpdb->get_col(
			$this->wpdb->prepare(
				'SELECT DISTINCT student_person_id FROM %i WHERE status = %s',
				$this->table,
				'active'
			)
		);

		return array_map( 'intval', $rows ?: array() );
	}

	/**
	 * Количество уникальных учеников по фильтрам.
	 *
	 * @param array $filters Массив фильтров
	 *
	 * @return int
	 */
	public function countDistinctStudents( array $filters = array() ): int {
		[ $where, $args ] = $this->buildWhereClause( $filters );

		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare( "SELECT COUNT(DISTINCT student_person_id) FROM %i {$where}", ...$args )
		);
	}

	/**
	 * Формирует WHERE-условие и массив параметров для prepare.
	 *
	 * @param array $filters Массив фильтров
	 *
	 * @return array{0: string, 1: array}
	 */
	public function countByGroupAndPerson( int $groupId, int $personId ): int {
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE group_id = %d AND student_person_id = %d',
				$this->table,
				$groupId,
				$personId
			)
		);
	}

	public function countByGroupAndParent( int $groupId, int $parentPersonId ): int {
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE group_id = %d AND parent_person_id = %d',
				$this->table,
				$groupId,
				$parentPersonId
			)
		);
	}

	/** @return object[] Все записи ученика в группе (любые статусы). */
	/** @return \Inc\DTO\Enrollment\StudentRecordDTO[] Все записи ученика в группе (любые статусы). */
	public function findAllByStudentAndGroup( int $studentPersonId, int $groupId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE student_person_id = %d AND group_id = %d ORDER BY enrolled_at ASC',
				$this->table,
				$studentPersonId,
				$groupId
			),
			ARRAY_A
		);
		return array_map( fn( array $r ) => StudentRecordDTO::fromArray( $r ), $rows ?: array() );
	}

	private function buildWhereClause( array $filters ): array {
		$where = 'WHERE 1=1';
		$args  = array( $this->table );

		// Фильтр по статусу (поддерживает массив статусов)
		if ( ! empty( $filters['status'] ) ) {
			$status = $filters['status'];
			if ( is_array( $status ) ) {
				$placeholders = implode( ', ', array_fill( 0, count( $status ), '%s' ) );
				$where       .= " AND status IN ({$placeholders})";
				array_push( $args, ...$status );
			} else {
				$where  .= ' AND status = %s';
				$args[] = $status;
			}
		}

		// Фильтр по ID студента
		if ( ! empty( $filters['student_person_id'] ) ) {
			$where  .= ' AND student_person_id = %d';
			$args[] = (int) $filters['student_person_id'];
		}

		return array( $where, $args );
	}
}