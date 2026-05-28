<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\Contracts\RepositoryInterface;
use Inc\DTO\RelationshipDTO;
use Inc\Enums\TableName;

/**
 * Class RelationshipRepository
 *
 * Репозиторий для управления связями законных представителей и учащихся
 * с фиксацией временных интервалов действия прав опеки.
 *
 * @package Inc\Repositories\WPDBRepositories
 *
 * ### Основные обязанности:
 *
 * 1. **Управление связями** — создание и обновление связей опекун-ученик.
 * 2. **Поиск по участникам** — получение всех связей для ученика или опекуна.
 * 3. **Проверка активности** — проверка наличия действующей связи на текущую дату.
 *
 * ### Архитектурная роль:
 *
 * Реализует интерфейс RepositoryInterface для единообразия с другими репозиториями.
 * Использует wpdb для прямых SQL-запросов. Работает с DTO RelationshipDTO
 * для типобезопасной передачи данных.
 *
 * ### Временные интервалы:
 *
 * - valid_from — дата начала действия связи
 * - valid_to — дата окончания действия (NULL означает "бессрочно")
 */
class RelationshipRepository implements RepositoryInterface {

	private \wpdb $wpdb;
	private string $table;

	/**
	 * Конструктор репозитория.
	 *
	 * @param \wpdb|null $wpdb Глобальный объект базы данных WordPress
	 */
	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb  = $wpdb ?? $GLOBALS['wpdb'];
		$this->table = TableName::Relationships->prefixed();
	}

	/**
	 * Находит связь по ID.
	 *
	 * @param int $id ID связи
	 *
	 * @return RelationshipDTO|null
	 */
	public function find( int $id ): ?RelationshipDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', $this->table, $id ),
			ARRAY_A
		);

		return $row ? RelationshipDTO::fromArray( $row ) : null;
	}

	/**
	 * Находит все связи ученика (кто является его законными представителями).
	 *
	 * @param int $studentPersonId ID ученика из таблицы persons
	 *
	 * @return RelationshipDTO[]
	 */
	public function findByStudent( int $studentPersonId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE student_person_id = %d ORDER BY valid_from DESC',
				$this->table,
				$studentPersonId
			),
			ARRAY_A
		);

		return array_map( fn( array $row ) => RelationshipDTO::fromArray( $row ), $rows ?: array() );
	}

	/**
	 * Находит все связи опекуна (за какими учениками он закреплён).
	 *
	 * @param int $guardianPersonId ID опекуна из таблицы persons
	 *
	 * @return RelationshipDTO[]
	 */
	public function findByGuardian( int $guardianPersonId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE guardian_person_id = %d ORDER BY valid_from DESC',
				$this->table,
				$guardianPersonId
			),
			ARRAY_A
		);

		return array_map( fn( array $row ) => RelationshipDTO::fromArray( $row ), $rows ?: array() );
	}

	/**
	 * Проверяет наличие активной связи между опекуном и учеником на текущую дату.
	 *
	 * @param int $guardianPersonId ID опекуна
	 * @param int $studentPersonId  ID ученика
	 *
	 * @return bool
	 */
	public function hasActiveRelationship( int $guardianPersonId, int $studentPersonId ): bool {
		// current_time( 'Y-m-d' ) — текущая дата в формате MySQL
		$today = current_time( 'Y-m-d' );

		return 0 < (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE guardian_person_id = %d AND student_person_id = %d AND valid_from <= %s AND (valid_to IS NULL OR valid_to >= %s)',
				$this->table,
				$guardianPersonId,
				$studentPersonId,
				$today,
				$today
			)
		);
	}

	/**
	 * Создаёт новую запись связи опекун-ученик.
	 *
	 * @param array $data Массив полей таблицы (guardian_person_id, student_person_id, relation_type, valid_from, valid_to)
	 *
	 * @return int ID созданной записи
	 */
	public function create( array $data ): int {
		$this->wpdb->insert( $this->table, $data );
		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Обновляет существующую запись связи.
	 *
	 * @param int   $id   ID связи
	 * @param array $data Массив обновляемых полей
	 *
	 * @return bool
	 */
	public function update( int $id, array $data ): bool {
		return false !== $this->wpdb->update( $this->table, $data, array( 'id' => $id ) );
	}
}
