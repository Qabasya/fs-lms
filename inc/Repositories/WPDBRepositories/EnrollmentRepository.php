<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\Contracts\RepositoryInterface;
use Inc\DTO\EnrollmentDTO;
use Inc\Enums\EnrollmentStatus;
use Inc\Enums\TableName;

/**
 * Class EnrollmentRepository
 *
 * Репозиторий для фиксации и контроля фактов зачисления учащихся на учебные программы и группы.
 *
 * @package Inc\Repositories\WPDBRepositories
 *
 * ### Основные обязанности:
 *
 * 1. **Создание зачислений** — запись факта зачисления студента на предмет/группу.
 * 2. **Поиск зачислений** — получение зачислений по ID, по студенту.
 * 3. **Управление статусом** — обновление статуса зачисления (active, finished, expelled, transferred).
 *
 * ### Архитектурная роль:
 *
 * Реализует интерфейс RepositoryInterface для единообразия с другими репозиториями.
 * Использует wpdb для прямых SQL-запросов. Работает с DTO EnrollmentDTO
 * для типобезопасной передачи данных о зачислении.
 */
class EnrollmentRepository implements RepositoryInterface {

	private \wpdb $wpdb;
	private string $table;

	/**
	 * Конструктор репозитория.
	 *
	 * @param \wpdb|null $wpdb Глобальный объект базы данных WordPress
	 */
	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb  = $wpdb ?? $GLOBALS['wpdb'];
		$this->table = TableName::Enrollments->prefixed();
	}

	/**
	 * Находит зачисление по ID.
	 *
	 * @param int $id ID зачисления
	 *
	 * @return EnrollmentDTO|null
	 */
	public function find( int $id ): ?EnrollmentDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', $this->table, $id ),
			ARRAY_A
		);

		return $row ? EnrollmentDTO::fromArray( $row ) : null;
	}

	/**
	 * Находит все зачисления студента (включая завершённые).
	 *
	 * @param int $studentPersonId ID студента из таблицы persons
	 *
	 * @return EnrollmentDTO[]
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

		return array_map( fn( array $row ) => EnrollmentDTO::fromArray( $row ), $rows ?: array() );
	}

	/**
	 * Находит активные зачисления студента (статус active).
	 *
	 * @param int $studentPersonId ID студента из таблицы persons
	 *
	 * @return EnrollmentDTO[]
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

		return array_map( fn( array $row ) => EnrollmentDTO::fromArray( $row ), $rows ?: array() );
	}

	/**
	 * Создаёт новую запись зачисления.
	 *
	 * @param array $data Массив полей таблицы (student_person_id, subject_key, period_key, status и т.д.)
	 *
	 * @return int ID созданной записи
	 */
	public function create( array $data ): int {
		$this->wpdb->insert( $this->table, $data );
		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Обновляет существующую запись зачисления.
	 *
	 * @param int   $id   ID зачисления
	 * @param array $data Массив обновляемых полей
	 *
	 * @return bool
	 */
	public function update( int $id, array $data ): bool {
		return false !== $this->wpdb->update( $this->table, $data, array( 'id' => $id ) );
	}

	/**
	 * Изменяет статус зачисления.
	 *
	 * @param int              $id     ID зачисления
	 * @param EnrollmentStatus $status Новый статус
	 *
	 * @return bool
	 */
	public function setStatus( int $id, EnrollmentStatus $status ): bool {
		return $this->update( $id, array(
			'status'     => $status->value,
			'updated_at' => current_time( 'mysql' ),  // Автоматическое обновление времени
		) );
	}

	public function findBySourceApplication( int $appId ): ?EnrollmentDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE source_application_id = %d LIMIT 1',
				$this->table,
				$appId
			),
			ARRAY_A
		);

		return $row ? EnrollmentDTO::fromArray( $row ) : null;
	}

	public function existsActive( int $personId, string $subjectKey, string $periodKey ): bool {
		return 0 < (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE student_person_id = %d AND subject_key = %s AND period_key = %s AND status = %s',
				$this->table,
				$personId,
				$subjectKey,
				$periodKey,
				EnrollmentStatus::Active->value
			)
		);
	}

	/**
	 * Возвращает список зачислений с пагинацией.
	 *
	 * @param array<string, string> $filters Фильтры (status)
	 * @param int                   $page    Номер страницы (с 1)
	 * @param int                   $perPage Записей на страницу
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list( array $filters = array(), int $page = 1, int $perPage = 20 ): array {
		$offset = ( $page - 1 ) * $perPage;
		$where  = 'WHERE 1=1';
		$args   = array( $this->table );

		if ( ! empty( $filters['status'] ) ) {
			$where  .= ' AND status = %s';
			$args[] = $filters['status'];
		}

		$args[] = $perPage;
		$args[] = $offset;

		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM %i {$where} ORDER BY enrolled_at DESC LIMIT %d OFFSET %d",
				...$args
			),
			ARRAY_A
		);

		return $rows ?: array();
	}

	/**
	 * Возвращает количество зачислений по фильтрам.
	 *
	 * @param array<string, string> $filters Фильтры (status)
	 *
	 * @return int
	 */
	public function count( array $filters = array() ): int {
		$where = 'WHERE 1=1';
		$args  = array( $this->table );

		if ( ! empty( $filters['status'] ) ) {
			$where  .= ' AND status = %s';
			$args[] = $filters['status'];
		}

		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare( "SELECT COUNT(*) FROM %i {$where}", ...$args )
		);
	}
}