<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\GroupLessonInputDTO;
use Inc\Enums\Course\LessonStatus;
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

	/**
	 * Все строки доставки конкретного урока (по всем группам). D17.3: нужно для
	 * orphan-aware учёта использования и reconcile при удалении урока из курса.
	 *
	 * @return GroupLessonDTO[]
	 */
	public function listByLesson( int $lessonId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE lesson_id = %d',
				$this->table,
				$lessonId
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
			// Индивидуальные и пиннутые привязаны к своей дате, а не к последовательности — не двигаем.
			if ( $row->isPinned || 'individual' === $row->kind ) {
				continue;
			}
			$status = LessonStatus::fromValueOrDefault( $row->status );
			// T11.6: проведённое занятие фиксирует свою дату (факт), но ЗАНИМАЕТ слот
			// в последовательности — нерассказанный хвост раскладывается после него.
			if ( LessonStatus::Held === $status ) {
				++$i;
				continue;
			}
			// T11.6: отменённое/перенесённое ОСВОБОЖДАЕТ слот — хвост сдвигается вперёд
			// (слот не тратится, дата не переписывается).
			if ( $status->freesSlot() ) {
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

	/**
	 * Дедлайны работ занятия (T12.2, D13): work_id => 'Y-m-d H:i:s'.
	 *
	 * @param array<int,string> $deadlines
	 */
	public function setWorkDeadlines( int $id, array $deadlines ): bool {
		$result = $this->wpdb->update(
			$this->table,
			array( 'work_deadlines' => wp_json_encode( $deadlines ) ),
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

	/** Указатель записи занятия (модуль VideoLibrary пишет `s3://{bucket}/{key}`). */
	public function setRecordingUrl( int $id, ?string $url ): bool {
		return false !== $this->wpdb->update(
			$this->table,
			array( 'recording_url' => $url ),
			array( 'id' => $id )
		);
	}

	/** План/факт занятия (V4: `held` при привязке записи фиксирует дату от reflow). */
	public function setStatus( int $id, LessonStatus $status ): bool {
		return false !== $this->wpdb->update(
			$this->table,
			array( 'status' => $status->value ),
			array( 'id' => $id )
		);
	}

	/**
	 * Занятия группы в календарный день (кандидаты резолва записи, V4).
	 *
	 * @param string $day День 'Y-m-d' в TZ сайта (scheduled_at — локальный wall-clock).
	 *
	 * @return GroupLessonDTO[]
	 */
	public function listByGroupAndDay( int $groupId, string $day ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE group_id = %d AND DATE(scheduled_at) = %s ORDER BY scheduled_at ASC',
				$this->table,
				$groupId,
				$day
			),
			ARRAY_A
		);
		return array_map( [ GroupLessonDTO::class, 'fromArray' ], $rows ?: array() );
	}

	/**
	 * Индивидуальные занятия преподавателя в календарный день по всем его группам (V4).
	 * Эффективный препод: `teacher_user_id` занятия, иначе `teacher_id` группы.
	 *
	 * @param string $day День 'Y-m-d' в TZ сайта.
	 *
	 * @return GroupLessonDTO[]
	 */
	public function listIndividualByTeacherAndDay( int $teacherUserId, string $day ): array {
		$groups = TableName::Groups->prefixed();
		$rows   = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT gl.* FROM %i gl
				 JOIN %i g ON g.id = gl.group_id
				 WHERE gl.kind = 'individual'
				   AND DATE(gl.scheduled_at) = %s
				   AND ( gl.teacher_user_id = %d OR ( gl.teacher_user_id IS NULL AND g.teacher_id = %d ) )
				 ORDER BY gl.scheduled_at ASC",
				$this->table,
				$groups,
				$day,
				$teacherUserId,
				$teacherUserId
			),
			ARRAY_A
		);
		return array_map( [ GroupLessonDTO::class, 'fromArray' ], $rows ?: array() );
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

	/** B2: смена ученика индивидуального занятия. */
	public function setStudentPersonId( int $id, int $personId ): bool {
		return false !== $this->wpdb->update(
			$this->table,
			array( 'student_person_id' => $personId ),
			array( 'id' => $id ),
			array( '%d' ),
			array( '%d' )
		);
	}

	public function remove( int $id ): bool {
		return (bool) $this->wpdb->delete( $this->table, array( 'id' => $id ) );
	}

	/** @return GroupLessonDTO[] Все строки программы (всех групп), ссылающиеся на эталонный урок. */
	public function listByLessonId( int $lessonId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE lesson_id = %d',
				$this->table,
				$lessonId
			),
			ARRAY_A
		);
		return array_map( [ GroupLessonDTO::class, 'fromArray' ], $rows ?: array() );
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

	/** Снимает ссылку на удаляемый кабинет со всех занятий (RoomAssignmentService). */
	public function clearRoomId( int $roomId ): int {
		return (int) $this->wpdb->update( $this->table, array( 'room_id' => null ), array( 'room_id' => $roomId ) );
	}
}
