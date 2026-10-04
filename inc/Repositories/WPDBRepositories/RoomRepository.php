<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\DTO\Course\RoomDTO;
use Inc\Enums\Settings\TableName;
use Inc\Shared\Traits\RaisesDbError;

/**
 * Справочник кабинетов (fs_lms_rooms, Эпик 9) + запросы занятости по времени.
 *
 * Занятость считается на материализованных занятиях `group_lessons` через
 * эффективный кабинет (`group_lessons.room_id` ?? `groups.room_id`). Конец окна —
 * `ends_at`, а при NULL — `scheduled_at + 60 мин` (COALESCE-fallback).
 *
 * @package Inc\Repositories\WPDBRepositories
 */
class RoomRepository {

	use RaisesDbError;

	private \wpdb  $wpdb;
	private string $table;
	private string $glTable;
	private string $groupsTable;

	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb        = $wpdb ?? $GLOBALS['wpdb'];
		$this->table       = TableName::Rooms->prefixed();
		$this->glTable     = TableName::GroupLessons->prefixed();
		$this->groupsTable = TableName::Groups->prefixed();
	}

	/** @return RoomDTO[] */
	public function findAll( bool $onlyActive = false ): array {
		$sql = $onlyActive
			? 'SELECT * FROM %i WHERE deleted_at IS NULL AND is_active = 1 ORDER BY name ASC'
			: 'SELECT * FROM %i WHERE deleted_at IS NULL ORDER BY name ASC';
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $this->table ), ARRAY_A );
		return array_map( array( RoomDTO::class, 'fromArray' ), $rows ?: array() );
	}

	public function find( int $id ): ?RoomDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND deleted_at IS NULL LIMIT 1', $this->table, $id ),
			ARRAY_A
		);
		return $row ? RoomDTO::fromArray( $row ) : null;
	}

	/** @param array{name:string, seats:int, allowed_subjects:string[], is_active?:bool} $data */
	public function create( array $data ): int {
		$this->wpdb->insert(
			$this->table,
			array(
				'name'             => $data['name'],
				'seats'            => (int) $data['seats'],
				'allowed_subjects' => wp_json_encode( array_values( $data['allowed_subjects'] ?? array() ) ),
				'is_active'        => (int) ( $data['is_active'] ?? true ),
			)
		);
		return (int) $this->wpdb->insert_id;
	}

	/** @param array{name:string, seats:int, allowed_subjects:string[], is_active?:bool} $data */
	public function update( int $id, array $data ): bool {
		$result = $this->wpdb->update(
			$this->table,
			array(
				'name'             => $data['name'],
				'seats'            => (int) $data['seats'],
				'allowed_subjects' => wp_json_encode( array_values( $data['allowed_subjects'] ?? array() ) ),
				'is_active'        => (int) ( $data['is_active'] ?? true ),
			),
			array( 'id' => $id )
		);
		return false !== $result;
	}

	/** Мягкое удаление (deleted_at); занятия с этим room_id остаются, но кабинет исчезает из списка. */
	public function softDelete( int $id ): bool {
		return false !== $this->wpdb->update( $this->table, array( 'deleted_at' => current_time( 'mysql' ) ), array( 'id' => $id ) );
	}

	/** Физическое удаление строки кабинета (D2: hard-delete вместо soft). */
	public function hardDelete( int $id ): bool {
		return false !== $this->wpdb->delete( $this->table, array( 'id' => $id ) );
	}

	/**
	 * Вычищает ключ предмета из `allowed_subjects` всех кабинетов (при удалении предмета).
	 * Поле — longtext с JSON-массивом, а не нативный JSON-тип, поэтому правка построчно в PHP.
	 */
	public function removeSubjectFromAll( string $subjectKey ): void {
		foreach ( $this->findAll() as $room ) {
			if ( ! in_array( $subjectKey, $room->allowedSubjects, true ) ) {
				continue;
			}
			$this->wpdb->update(
				$this->table,
				array( 'allowed_subjects' => wp_json_encode( array_values( array_diff( $room->allowedSubjects, array( $subjectKey ) ) ) ) ),
				array( 'id' => $room->id )
			);
		}
	}

	/**
	 * Занят ли кабинет в окне [$start,$end) кем-то, кроме $excludeGroupLessonId.
	 *
	 * @param string   $start 'Y-m-d H:i:s'.
	 * @param string   $end   'Y-m-d H:i:s'.
	 */
	/**
	 * @param int $excludeGroupLessonId одно занятие исключить (0 = никого).
	 * @param int $excludeGroupId       исключить ВСЕ занятия группы (для bulk-reflow, T11.4;
	 *                                  0 = не исключать, т.к. id групп всегда > 0).
	 */
	public function isBusy( int $roomId, string $start, string $end, int $excludeGroupLessonId = 0, int $excludeGroupId = 0 ): bool {
		$sql = "SELECT 1 FROM {$this->glTable} gl
				LEFT JOIN {$this->groupsTable} g ON g.id = gl.group_id
				WHERE COALESCE(gl.room_id, g.room_id) = %d
				  AND gl.id <> %d
				  AND gl.group_id <> %d
				  AND gl.scheduled_at IS NOT NULL
				  AND gl.scheduled_at < %s
				  AND COALESCE(gl.ends_at, gl.scheduled_at + INTERVAL 60 MINUTE) > %s
				LIMIT 1";
		return (bool) $this->wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->wpdb->prepare( $sql, $roomId, $excludeGroupLessonId, $excludeGroupId, $end, $start )
		);
	}

	/**
	 * Блокирует строку кабинета до конца транзакции. Назначения кабинета (занятие или сеанс экзамена) выполняются по очереди:
	 * проверка «свободен ли» и запись идут под этой блокировкой, поэтому второе назначение видит первое. Вызывать первым оператором
	 * транзакции. Ошибка базы — исключение (`RetryableDbException` при взаимной блокировке), а не «кабинета нет».
	 */
	public function lockForUpdate( int $roomId ): void {
		$this->wpdb->get_var( $this->wpdb->prepare( 'SELECT id FROM %i WHERE id = %d FOR UPDATE', $this->table, $roomId ) );
		if ( '' !== (string) $this->wpdb->last_error ) {
			$this->raiseDbError();
		}
	}

	/**
	 * Занятия, пересекающие окно (`start`, `end`) в кабинете, — для предупреждения о позднем старте экзамена.
	 * Условие пересечения то же, что в {@see isBusy()}.
	 *
	 * @param string $start 'Y-m-d H:i:s', местное время.
	 * @param string $end   'Y-m-d H:i:s', местное время.
	 *
	 * @return list<array{title: string, start: string}> Название занятия и начало (местное время), по возрастанию начала.
	 */
	public function listLessonsInWindow( int $roomId, string $start, string $end ): array {
		$sql  = "SELECT gl.label AS title, gl.scheduled_at AS lesson_start FROM {$this->glTable} gl
				LEFT JOIN {$this->groupsTable} g ON g.id = gl.group_id
				WHERE COALESCE(gl.room_id, g.room_id) = %d
				  AND gl.scheduled_at IS NOT NULL
				  AND gl.scheduled_at < %s
				  AND COALESCE(gl.ends_at, gl.scheduled_at + INTERVAL 60 MINUTE) > %s
				ORDER BY gl.scheduled_at ASC, gl.id ASC";
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $roomId, $end, $start ), ARRAY_A );
		if ( '' !== (string) $this->wpdb->last_error ) {
			$this->raiseDbError();
		}

		return array_map(
			static fn ( array $row ): array => array( 'title' => (string) ( $row['title'] ?? '' ), 'start' => (string) $row['lesson_start'] ),
			is_array( $rows ) ? $rows : array()
		);
	}
}
