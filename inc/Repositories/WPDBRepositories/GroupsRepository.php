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
	 * Находит все группы по ID периода.
	 *
	 * @param string $periodId ID учебного периода
	 *
	 * @return array
	 */
	public function findByPeriodId( string $periodId ): array {
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE academic_period_id = %s ORDER BY name ASC',
				$this->table,
				$periodId
			)
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
}