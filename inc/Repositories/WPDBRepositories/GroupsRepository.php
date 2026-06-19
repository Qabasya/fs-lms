<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\Enums\TableName;

/**
 * Class GroupsRepository
 *
 * Репозиторий для работы с группами студентов в wpdb (таблица fs_lms_groups).
 *
 * @package Inc\Repositories\WPDBRepositories
 *
 * ### Основные обязанности:
 *
 * 1. **CRUD-операции** — создание, чтение, обновление и удаление групп.
 * 2. **Поиск по различным критериям** — по ID, ключу предмета, периоду.
 *
 * ### Архитектурная роль:
 *
 * Инкапсулирует вызовы $wpdb для прямых SQL-запросов.
 * Использует enum TableName для получения имени таблицы с префиксом.
 * Работает напрямую с объектами результата запроса (не DTO).
 */
class GroupsRepository {

	private \wpdb $wpdb;
	private string $table;

	/**
	 * Конструктор репозитория.
	 *
	 * @param \wpdb|null $wpdb Глобальный объект базы данных WordPress
	 */
	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb  = $wpdb ?? $GLOBALS['wpdb'];
		$this->table = TableName::Groups->prefixed();
	}

	/**
	 * Находит группу по ID.
	 *
	 * @param int $id ID группы
	 *
	 * @return object|null
	 */
	public function findById( int $id ): ?object {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d LIMIT 1',
				$this->table,
				$id
			)
		);

		return $row ?: null;
	}

	/**
	 * Находит все группы по ключу предмета.
	 *
	 * @param string $subjectKey Ключ предмета (например, 'math')
	 *
	 * @return array
	 */
	public function findBySubjectKey( string $subjectKey ): array {
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE subject_key = %s ORDER BY name ASC',
				$this->table,
				$subjectKey
			)
		) ?: array();
	}

	/**
	 * Находит группы по ID периода и ключу предмета.
	 *
	 * @param string $periodId   ID учебного периода
	 * @param string $subjectKey Ключ предмета
	 *
	 * @return array
	 */
	public function findByPeriodAndSubject( string $periodId, string $subjectKey ): array {
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE academic_period_id = %s AND subject_key = %s ORDER BY name ASC',
				$this->table,
				$periodId,
				$subjectKey
			)
		) ?: array();
	}

	/**
	 * Находит группу по названию в рамках предмета и периода (find-or-create при импорте).
	 *
	 * @param string $name       Название группы
	 * @param string $subjectKey Ключ предмета
	 * @param string $periodId   ID учебного периода
	 *
	 * @return object|null Найденная группа или null
	 */
	public function findByNameSubjectPeriod( string $name, string $subjectKey, string $periodId ): ?object {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE name = %s AND subject_key = %s AND academic_period_id = %s LIMIT 1',
				$this->table,
				$name,
				$subjectKey,
				$periodId
			)
		);

		return $row ?: null;
	}

	/**
	 * Находит все группы по ID периода.
	 *
	 * @param string $periodId ID учебного периода
	 *
	 * @return array
	 */
	public function findByPeriodId( string $periodId ): array {
		return $this->findByFilters( $periodId );
	}

	public function findByFilters( string $periodId, string $subjectKey = '', int $teacherId = 0 ): array {
		$where    = array( 'academic_period_id = %s' );
		$bindings = array( $this->table, $periodId );

		if ( '' !== $subjectKey ) {
			$where[]    = 'subject_key = %s';
			$bindings[] = $subjectKey;
		}

		if ( $teacherId > 0 ) {
			$where[]    = 'teacher_id = %d';
			$bindings[] = $teacherId;
		}

		$sql = 'SELECT * FROM %i WHERE ' . implode( ' AND ', $where ) . ' ORDER BY name ASC';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $this->wpdb->get_results(
			$this->wpdb->prepare( $sql, $bindings )
		) ?: array();
	}

	/**
	 * Возвращает все группы, отсортированные по предмету и названию.
	 *
	 * @return array
	 */
	public function findAll(): array {
		return $this->wpdb->get_results(
			$this->wpdb->prepare( 'SELECT * FROM %i ORDER BY subject_key, name ASC', $this->table )
		) ?: array();
	}

	/**
	 * Возвращает количество групп в указанном учебном периоде.
	 *
	 * @param string $periodId ID учебного периода
	 *
	 * @return int
	 */
	public function countByPeriodId( string $periodId ): int {
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE academic_period_id = %s',
				$this->table,
				$periodId
			)
		);
	}

	/**
	 * Проверяет, существует ли группа с таким именем в данном периоде.
	 *
	 * @param string $name     Название группы
	 * @param string $periodId ID учебного периода
	 *
	 * @return bool
	 */
	public function existsByNameAndPeriod( string $name, string $periodId ): bool {
		$count = (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE name = %s AND academic_period_id = %s LIMIT 1',
				$this->table,
				$name,
				$periodId
			)
		);

		return $count > 0;
	}

	/**
	 * Создаёт новую группу.
	 *
	 * @param array $data Массив полей таблицы (name, subject_key, academic_period_id, teacher_id, schedule)
	 *
	 * @return int ID созданной группы
	 */
	public function create( array $data ): int {
		$this->wpdb->insert( $this->table, $data );
		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Обновляет существующую группу.
	 *
	 * @param int   $id   ID группы
	 * @param array $data Массив обновляемых полей
	 *
	 * @return bool
	 */
	public function update( int $id, array $data ): bool {
		return false !== $this->wpdb->update( $this->table, $data, array( 'id' => $id ) );
	}

	/**
	 * Удаляет группу (мягкое или физическое удаление).
	 *
	 * @param int $id ID группы
	 *
	 * @return bool
	 */
	public function delete( int $id ): bool {
		return false !== $this->wpdb->delete( $this->table, array( 'id' => $id ) );
	}

	/**
	 * Физически удаляет группу (синоним delete для ясности).
	 *
	 * @param int $id ID группы
	 *
	 * @return bool
	 */
	public function hardDelete( int $id ): bool {
		return $this->delete( $id );
	}

	/** @return array{weekday:int,time:string,duration_min:int}[] */
	public function getMeetings( int $groupId ): array {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT meetings FROM %i WHERE id = %d LIMIT 1',
				$this->table,
				$groupId
			)
		);
		if ( ! $row || ! $row->meetings ) {
			return array();
		}
		$decoded = json_decode( $row->meetings, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	public function setMeetings( int $groupId, array $meetings ): bool {
		return false !== $this->wpdb->update(
			$this->table,
			array( 'meetings' => wp_json_encode( $meetings ) ),
			array( 'id' => $groupId )
		);
	}
}