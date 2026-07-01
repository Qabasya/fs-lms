<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\GroupLessonInputDTO;
use Inc\Enums\Settings\TableName;

class GroupLessonRepository {

	private \wpdb  $wpdb;
	private string $table;

	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb  = $wpdb ?? $GLOBALS['wpdb'];
		$this->table = TableName::GroupLessons->prefixed();
	}

	/** @return GroupLessonDTO[] */
	public function listByGroup( int $groupId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE group_id = %d ORDER BY position ASC',
				$this->table,
				$groupId
			),
			ARRAY_A
		);
		return array_map( [ GroupLessonDTO::class, 'fromArray' ], $rows ?: array() );
	}

	/** @return GroupLessonDTO[] */
	public function listOpenByGroup( int $groupId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM %i WHERE group_id = %d AND visibility IN ('open','archived') ORDER BY position ASC",
				$this->table,
				$groupId
			),
			ARRAY_A
		);
		return array_map( [ GroupLessonDTO::class, 'fromArray' ], $rows ?: array() );
	}

	public function find( int $id ): ?GroupLessonDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d LIMIT 1',
				$this->table,
				$id
			),
			ARRAY_A
		);
		return $row ? GroupLessonDTO::fromArray( $row ) : null;
	}

	public function add( GroupLessonInputDTO $dto ): int {
		$this->wpdb->insert( $this->table, $dto->toArray() );
		return (int) $this->wpdb->insert_id;
	}

	public function nextPosition( int $groupId ): int {
		$max = $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT MAX(position) FROM %i WHERE group_id = %d',
				$this->table,
				$groupId
			)
		);
		return null === $max ? 0 : (int) $max + 1;
	}

	/** Bulk-update position по упорядоченному массиву ID. */
	public function reorder( int $groupId, array $orderedIds ): void {
		foreach ( $orderedIds as $pos => $id ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->wpdb->update(
				$this->table,
				array( 'position' => $pos ),
				array( 'id' => (int) $id, 'group_id' => $groupId )
			);
		}
	}

	public function updateSchedule( int $id, ?string $scheduledAt, ?int $teacherUserId, ?string $endsAt = null ): bool {
		$result = $this->wpdb->update(
			$this->table,
			array(
				'scheduled_at'    => $scheduledAt,
				'ends_at'         => $endsAt,
				'teacher_user_id' => $teacherUserId,
			),
			array( 'id' => $id )
		);
		return false !== $result;
	}

	public function setPinned( int $id, bool $pinned ): bool {
		$result = $this->wpdb->update(
			$this->table,
			array( 'is_pinned' => (int) $pinned ),
			array( 'id' => $id )
		);
		return false !== $result;
	}

	/** Bulk-assign slots from SessionCalendarService::generate(); skips pinned rows. */
	public function applySlots( int $groupId, array $slots ): void {
		$rows = $this->listByGroup( $groupId );
		$i    = 0;
		foreach ( $rows as $row ) {
			// Индивидуальные привязаны к дате, а не к последовательности — не двигаем.
			if ( $row->isPinned || 'individual' === $row->kind ) {
				continue;
			}
			if ( ! isset( $slots[ $i ] ) ) {
				break;
			}
			$this->wpdb->update(
				$this->table,
				array(
					'scheduled_at' => $slots[ $i ]['scheduled_at'],
					'ends_at'      => $slots[ $i ]['ends_at'],
					// Кабинет дня недели (Эпик 10): переносится из расписания в занятие.
					'room_id'      => ! empty( $slots[ $i ]['room'] ) ? (int) $slots[ $i ]['room'] : null,
				),
				array( 'id' => $row->id )
			);
			$i++;
		}
	}

	public function setVisibility( int $id, string $visibility, ?string $openedAt ): bool {
		$data = array( 'visibility' => $visibility );
		if ( null !== $openedAt ) {
			$data['opened_at'] = $openedAt;
		}
		$result = $this->wpdb->update( $this->table, $data, array( 'id' => $id ) );
		return false !== $result;
	}

	public function setWorkIdsSnapshot( int $id, array $workIds ): bool {
		$result = $this->wpdb->update(
			$this->table,
			array( 'work_ids_snapshot' => json_encode( $workIds ) ),
			array( 'id' => $id )
		);
		return false !== $result;
	}

	public function setExtraWorkIds( int $id, array $workIds ): bool {
		$result = $this->wpdb->update(
			$this->table,
			array( 'extra_work_ids' => json_encode( $workIds ) ),
			array( 'id' => $id )
		);
		return false !== $result;
	}

	public function setStepSettingsOverrides( int $id, array $overrides ): bool {
		$result = $this->wpdb->update(
			$this->table,
			array( 'step_settings_overrides' => wp_json_encode( $overrides ) ),
			array( 'id' => $id )
		);
		return false !== $result;
	}

	public function setRoom( int $id, ?int $roomId ): bool {
		return false !== $this->wpdb->update(
			$this->table,
			array( 'room_id' => $roomId ),
			array( 'id' => $id )
		);
	}

	public function setLessonId( int $id, int $lessonId ): bool {
		$result = $this->wpdb->update(
			$this->table,
			array( 'lesson_id' => $lessonId ),
			array( 'id' => $id ),
			array( '%d' ),
			array( '%d' )
		);
		return false !== $result;
	}

	public function remove( int $id ): bool {
		return (bool) $this->wpdb->delete( $this->table, array( 'id' => $id ) );
	}

	public function countUsageByLesson( int $lessonId ): int {
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE lesson_id = %d',
				$this->table,
				$lessonId
			)
		);
	}

	public function deleteAllByGroup( int $groupId ): int {
		return (int) $this->wpdb->delete( $this->table, array( 'group_id' => $groupId ) );
	}
}
