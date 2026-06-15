<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\DTO\Person\PersonDTO;
use Inc\DTO\Person\PersonRecordInputDTO;
use Inc\Enums\TableName;

/**
 * Class PersonRepository
 *
 * Репозиторий для управления сущностями физических лиц (ученики, родители, преподаватели).
 *
 * @package Inc\Repositories\WPDBRepositories
 *
 * ### Основные обязанности:
 *
 * 1. **CRUD-операции** — создание, чтение, обновление и удаление записей о лицах.
 * 2. **Мягкое удаление** — поддержка expelled_at для сохранения истории.
 * 3. **Поиск по различным критериям** — по ID, WP User ID, флагу is_student.
 *
 * ### Архитектурная роль:
 *
 * Инкапсулирует вызовы $wpdb для прямых SQL-запросов.
 * Использует DTO PersonDTO для типобезопасной передачи данных.
 * Реализует интерфейс RepositoryInterface для единообразия с другими репозиториями.
 */
class PersonRepository {

	private \wpdb $wpdb;
	private string $table;

	/**
	 * Конструктор репозитория.
	 *
	 * @param \wpdb|null $wpdb Глобальный объект базы данных WordPress
	 */
	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb  = $wpdb ?? $GLOBALS['wpdb'];
		$this->table = TableName::Persons->prefixed();
	}

	/**
	 * Находит лицо по ID (только активные, не удалённые).
	 *
	 * @param int $id ID записи
	 *
	 * @return PersonDTO|null
	 */
	public function find( int $id ): ?PersonDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d AND expelled_at IS NULL LIMIT 1',
				$this->table,
				$id
			),
			ARRAY_A
		);

		return $row ? PersonDTO::fromArray( $row ) : null;
	}

	/**
	 * @param int[] $ids
	 * @return PersonDTO[] indexed by id
	 */
	public function findByIds( array $ids ): array {
		if ( empty( $ids ) ) {
			return array();
		}
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$bindings     = array_merge( array( $this->table ), $ids );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT * FROM %i WHERE id IN ($placeholders)", $bindings ),
			ARRAY_A
		) ?: array();
		$result = array();
		foreach ( $rows as $row ) {
			$dto              = PersonDTO::fromArray( $row );
			$result[ $dto->id ] = $dto;
		}
		return $result;
	}

	public function findIncludingDeleted( int $id ): ?PersonDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d LIMIT 1',
				$this->table,
				$id
			),
			ARRAY_A
		);

		return $row ? PersonDTO::fromArray( $row ) : null;
	}

	/**
	 * Находит лицо по ID пользователя WordPress.
	 *
	 * @param int $wpUserId ID пользователя WP
	 *
	 * @return PersonDTO|null
	 */
	public function findByWpUserId( int $wpUserId ): ?PersonDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE wp_user_id = %d AND expelled_at IS NULL LIMIT 1',
				$this->table,
				$wpUserId
			),
			ARRAY_A
		);

		return $row ? PersonDTO::fromArray( $row ) : null;
	}

	/**
	 * Находит лицо по ФИО (+ дата рождения, если задана) и роли.
	 *
	 * Используется при импорте для дедупликации persons, у которых
	 * нет документа и email. Поиск ведётся по всем записям (включая
	 * мягко удалённые), чтобы повторный импорт не плодил дубли.
	 *
	 * @param string      $lastName   Фамилия
	 * @param string      $firstName  Имя
	 * @param string|null $middleName Отчество (null/'' — искать пустое)
	 * @param string|null $birthDate  Дата рождения Y-m-d (null — не ограничивать)
	 * @param bool        $isStudent  true — ученик, false — родитель
	 *
	 * @return PersonDTO|null
	 */
	public function findByNameAndBirthDate(
		string $lastName,
		string $firstName,
		?string $middleName,
		?string $birthDate,
		bool $isStudent
	): ?PersonDTO {
		$where    = array( 'last_name = %s', 'first_name = %s', 'is_student = %d' );
		$bindings = array( $this->table, $lastName, $firstName, $isStudent ? 1 : 0 );

		if ( null !== $middleName && '' !== $middleName ) {
			$where[]    = 'middle_name = %s';
			$bindings[] = $middleName;
		} else {
			$where[] = "( middle_name IS NULL OR middle_name = '' )";
		}

		if ( null !== $birthDate && '' !== $birthDate ) {
			$where[]    = 'birth_date = %s';
			$bindings[] = $birthDate;
		}

		$sql = 'SELECT * FROM %i WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id LIMIT 1';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $this->wpdb->get_row( $this->wpdb->prepare( $sql, $bindings ), ARRAY_A );

		return $row ? PersonDTO::fromArray( $row ) : null;
	}

	/**
	 * Создаёт новую запись лица.
	 *
	 * @param PersonRecordInputDTO $dto DTO с полями для вставки
	 *
	 * @return int ID созданной записи
	 */
	public function create( PersonRecordInputDTO $dto ): int {
		$this->wpdb->insert( $this->table, $dto->toArray() );
		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Обновляет существующую запись лица.
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
	 * Мягкое удаление (реализация интерфейса).
	 * Заполняет поле expelled_at текущей датой.
	 *
	 * @param int $id ID записи
	 *
	 * @return bool
	 */
	public function delete( int $id ): bool {
		return $this->softDelete( $id );
	}

	/**
	 * Физическое удаление записи из БД.
	 *
	 * @param int $id ID записи
	 *
	 * @return bool
	 */
	public function hardDelete( int $id ): bool {
		return false !== $this->wpdb->delete( $this->table, array( 'id' => $id ) );
	}

	/**
	 * Мягкое удаление записи (заполнение expelled_at).
	 *
	 * @param int $id ID записи
	 *
	 * @return bool
	 */
	public function softDelete( int $id ): bool {
		return $this->update( $id, array( 'expelled_at' => current_time( 'mysql', true ) ) );
	}

	/**
	 * Привязывает запись лица к пользователю WordPress.
	 *
	 * @param int $id       ID записи лица
	 * @param int $wpUserId ID пользователя WP
	 *
	 * @return bool
	 */
	public function setWpUser( int $id, int $wpUserId ): bool {
		return $this->update( $id, array( 'wp_user_id' => $wpUserId ) );
	}

	/**
	 * Находит всех лиц с указанным флагом is_student.
	 *
	 * @param bool $isStudent true — ученики, false — не ученики (родители, преподаватели)
	 *
	 * @return PersonDTO[]
	 */
	public function findByIsStudent( bool $isStudent ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE is_student = %d AND expelled_at IS NULL ORDER BY last_name, first_name',
				$this->table,
				$isStudent ? 1 : 0
			),
			ARRAY_A
		);

		return array_map( fn( array $row ) => PersonDTO::fromArray( $row ), $rows ?: array() );
	}

	/**
	 * Находит мягко удалённых лиц старше указанного количества дней.
	 * Используется для окончательной анонимизации retention-задачами.
	 *
	 * @param int $days Количество дней после мягкого удаления
	 *
	 * @return PersonDTO[]
	 */
	public function findDeletedOlderThan( int $days ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE expelled_at IS NOT NULL AND expelled_at < DATE_SUB(NOW(), INTERVAL %d DAY)',
				$this->table,
				$days
			),
			ARRAY_A
		);

		return array_map( fn( array $row ) => PersonDTO::fromArray( $row ), $rows ?: array() );
	}
}