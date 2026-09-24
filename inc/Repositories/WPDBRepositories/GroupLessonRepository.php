<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\GroupLessonInputDTO;
use Inc\Enums\Course\LessonKind;
use Inc\Enums\Course\LessonStatus;
use Inc\Enums\Settings\TableName;

class GroupLessonRepository {

	/**
	 * Вычисляемый флаг «по занятию отмечена посещаемость» для выборок с алиасом
	 * `gl`; первым плейсхолдером запроса идёт таблица посещаемости.
	 */
	private const HAS_ATTENDANCE = 'EXISTS( SELECT 1 FROM %i a WHERE a.group_lesson_id = gl.id ) AS has_attendance';

	private \wpdb  $wpdb;
	private string $table;

	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb  = $wpdb ?? $GLOBALS['wpdb'];
		$this->table = TableName::GroupLessons->prefixed();
	}

	/**
	 * Строки программы группы по порядку. Каждая несёт `has_attendance` —
	 * отмечена ли по занятию посещаемость ({@see GroupLessonDTO::isFact()}).
	 *
	 * @return GroupLessonDTO[]
	 */
	public function listByGroup( int $groupId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT gl.*, ' . self::HAS_ATTENDANCE . ' FROM %i gl WHERE gl.group_id = %d ORDER BY gl.position ASC',
				TableName::Attendance->prefixed(),
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
				'SELECT gl.*, ' . self::HAS_ATTENDANCE . ' FROM %i gl WHERE gl.id = %d LIMIT 1',
				TableName::Attendance->prefixed(),
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

	/**
	 * Освобождает позицию под вставку в середину программы: строки группы с
	 * `position >= $fromPosition` сдвигаются на одну вниз.
	 */
	public function shiftPositions( int $groupId, int $fromPosition ): void {
		$this->wpdb->query(
			$this->wpdb->prepare(
				'UPDATE %i SET position = position + 1 WHERE group_id = %d AND position >= %d',
				$this->table,
				$groupId,
				$fromPosition
			)
		);
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
			array_merge(
				array(
					'scheduled_at'    => $scheduledAt,
					'ends_at'         => $endsAt,
					'teacher_user_id' => $teacherUserId,
				),
				$this->deadlinesFollowing( $this->find( $id ), $scheduledAt )
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

	/**
	 * Переносит строку на освободившееся окно занятия целиком: начало, конец и
	 * кабинет того занятия, которое это окно освободило. В отличие от
	 * {@see updateSchedule()} кабинет тоже едет за датой — окно принадлежит дню
	 * расписания, а не строке программы (сдвиг хвоста после возврата темы в пул,
	 * {@see \Inc\Services\Group\ScheduleReflowService::returnToPool()}).
	 *
	 * @param array{scheduled_at:string, ends_at:?string, room_id:?int} $slot Освободившееся окно
	 */
	public function moveToSlot( int $id, array $slot ): bool {
		$result = $this->wpdb->update(
			$this->table,
			array_merge(
				array(
					'scheduled_at' => $slot['scheduled_at'],
					'ends_at'      => $slot['ends_at'],
					'room_id'      => $slot['room_id'],
				),
				$this->deadlinesFollowing( $this->find( $id ), $slot['scheduled_at'] )
			),
			array( 'id' => $id )
		);
		return false !== $result;
	}

	/**
	 * Дедлайны работ едут вместе с занятием: при переносе с одной даты на другую
	 * per-work дедлайны и legacy `homework_due_at` сдвигаются на ту же разницу —
	 * «сдать к следующему занятию» остаётся ровно таким. Первая постановка на
	 * дату (строка была в пуле) и снятие даты дедлайнов не касаются: сдвигать
	 * не от чего.
	 *
	 * @return array<string, string|null> Поля для UPDATE (пусто — менять нечего).
	 */
	private function deadlinesFollowing( ?GroupLessonDTO $row, ?string $newStart ): array {
		if ( null === $row || null === $row->scheduledAt || null === $newStart || $row->scheduledAt === $newStart ) {
			return array();
		}
		if ( array() === $row->workDeadlines && null === $row->homeworkDueAt ) {
			return array();
		}

		$delta = ( new \DateTimeImmutable( $newStart ) )->getTimestamp()
			- ( new \DateTimeImmutable( $row->scheduledAt ) )->getTimestamp();
		$shift = static fn( string $at ): string => ( new \DateTimeImmutable( $at ) )
			->modify( sprintf( '%+d seconds', $delta ) )
			->format( 'Y-m-d H:i:s' );

		$fields = array();
		if ( array() !== $row->workDeadlines ) {
			$fields['work_deadlines'] = wp_json_encode( array_map( $shift, $row->workDeadlines ) );
		}
		if ( null !== $row->homeworkDueAt ) {
			$fields['homework_due_at'] = $shift( $row->homeworkDueAt );
		}

		return $fields;
	}

	/**
	 * Снимает дату/закрепление/кабинет со строки — возвращает тему в пул «Темы
	 * курса». Используется при вытеснении занятой даты (Этап 3: строгая замена)
	 * и обнулении хвоста сверх слотов (Этап 1: {@see applySlots()}).
	 * `teacher_user_id` не трогаем — назначение преподавателя не привязано к дате.
	 */
	public function clearSchedule( int $id ): bool {
		$result = $this->wpdb->update(
			$this->table,
			array(
				'scheduled_at' => null,
				'ends_at'      => null,
				'room_id'      => null,
				'is_pinned'    => 0,
			),
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
			if ( $row->isPinned || $row->kind->isIndividual() ) {
				// Пиннутая строка — курсор слотов сдвигаем за её дату, чтобы следующие
				// непиннутые темы раскладывались ПОСЛЕ неё, а не с начала периода.
				if ( $row->isPinned && $row->scheduledAt ) {
					while ( isset( $slots[ $i ] ) && $slots[ $i ]['scheduled_at'] <= $row->scheduledAt ) {
						++$i;
					}
				}
				continue;
			}
			$status = LessonStatus::fromValueOrDefault( $row->status );
			// T11.6: состоявшееся занятие (проведено или отмечена посещаемость) фиксирует
			// свою дату — это факт журнала, но ЗАНИМАЕТ слот в последовательности —
			// нерассказанный хвост раскладывается после него.
			if ( $row->isFact() ) {
				++$i;
				continue;
			}
			// T11.6: отменённое/перенесённое ОСВОБОЖДАЕТ слот — хвост сдвигается вперёд
			// (слот не тратится, дата не переписывается).
			if ( $status->freesSlot() ) {
				continue;
			}
			if ( ! isset( $slots[ $i ] ) ) {
				// Слотов меньше, чем строк (курс больше периода, T1): хвост не «зависает»
				// на старой дате (напр. после укорачивания периода) — возвращается в пул
				// «Темы курса» без даты и закрепления, чтобы автор мог разместить его вручную
				// или вне расписания (Этап 4). held/индивидуальные сюда не попадают — они уже
				// отфильтрованы выше.
				$this->clearSchedule( $row->id );
				continue;
			}
			$this->wpdb->update(
				$this->table,
				array_merge(
					array(
						'scheduled_at' => $slots[ $i ]['scheduled_at'],
						'ends_at'      => $slots[ $i ]['ends_at'],
						// Кабинет дня недели (Эпик 10): переносится из расписания в занятие.
						'room_id'      => ! empty( $slots[ $i ]['room'] ) ? (int) $slots[ $i ]['room'] : null,
					),
					$this->deadlinesFollowing( $row, $slots[ $i ]['scheduled_at'] )
				),
				array( 'id' => $row->id )
			);
			$i++;
		}
	}

	/**
	 * Отменяет распределение группы: снимает дату/закрепление/кабинет со всех
	 * непроведённых групповых занятий (индивидуальные, проведённые и с отмеченной
	 * посещаемостью — не трогаем, это исторический факт журнала). Темы возвращаются
	 * в пул «Темы курса».
	 *
	 * @return int Количество затронутых строк.
	 */
	public function unscheduleAll( int $groupId ): int {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE %i gl SET gl.scheduled_at = NULL, gl.ends_at = NULL, gl.room_id = NULL, gl.is_pinned = 0, gl.status = 'scheduled'
				 WHERE gl.group_id = %d AND gl.kind != %s AND gl.status != 'held'
				   AND NOT EXISTS( SELECT 1 FROM %i a WHERE a.group_lesson_id = gl.id )",
				$this->table,
				$groupId,
				LessonKind::Individual->value,
				TableName::Attendance->prefixed()
			)
		);
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
				'SELECT gl.*, ' . self::HAS_ATTENDANCE . ' FROM %i gl WHERE gl.group_id = %d AND DATE(gl.scheduled_at) = %s ORDER BY gl.scheduled_at ASC',
				TableName::Attendance->prefixed(),
				$this->table,
				$groupId,
				$day
			),
			ARRAY_A
		);
		return array_map( [ GroupLessonDTO::class, 'fromArray' ], $rows ?: array() );
	}

	/**
	 * Проведённые занятия без записи (З3): `status = held`, `recording_url` пуст.
	 * Источник алёрта «запись не привязалась» в админке — авто-матч VideoLibrary
	 * мог не сработать, ссылку вставляют вручную. Свежие сверху.
	 *
	 * @return GroupLessonDTO[]
	 */
	public function listHeldWithoutRecording( int $limit = 50 ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM %i WHERE status = 'held' AND ( recording_url IS NULL OR recording_url = '' )
				 ORDER BY scheduled_at DESC LIMIT %d",
				$this->table,
				$limit
			),
			ARRAY_A
		);
		return array_map( [ GroupLessonDTO::class, 'fromArray' ], $rows ?: array() );
	}

	/** Сколько проведённых занятий осталось без записи (счётчик алёрта, З3). */
	public function countHeldWithoutRecording(): int {
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE status = 'held' AND ( recording_url IS NULL OR recording_url = '' )",
				$this->table
			)
		);
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
				 WHERE gl.kind = %s
				   AND DATE(gl.scheduled_at) = %s
				   AND ( gl.teacher_user_id = %d OR ( gl.teacher_user_id IS NULL AND g.teacher_id = %d ) )
				 ORDER BY gl.scheduled_at ASC",
				$this->table,
				$groups,
				LessonKind::Individual->value,
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

	/**
	 * Запланированные занятия, начинающиеся в интервале ($from, $to] — cron-продюсер
	 * уведомления «занятие скоро» ({@see \Inc\Services\Profile\NotificationCronService}).
	 * `visibility` намеренно не фильтруется — расписание ученику видно всегда.
	 *
	 * @return GroupLessonDTO[]
	 */
	public function listStartingBetween( string $from, string $to ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM %i WHERE status = 'scheduled' AND scheduled_at > %s AND scheduled_at <= %s",
				$this->table,
				$from,
				$to
			),
			ARRAY_A
		);
		return array_map( [ GroupLessonDTO::class, 'fromArray' ], $rows ?: array() );
	}

	/**
	 * Занятия, у которых `scheduled_at` уже прошёл, но в базе они всё ещё числятся
	 * `hidden` (Этап 5, Tasks.md) — кандидаты уведомления «Открыт урок». `visibility`
	 * в БД не переписывается автопереходом hidden→open (тот ленивый, только на чтение,
	 * {@see \Inc\Services\Course\LessonVisibilityService::effectiveVisibility()}), поэтому
	 * такая строка продолжает попадать в выборку на каждом тике сколько угодно — сервис
	 * различает «уже уведомляли» через `dedupe_key`, а не через эту выборку.
	 *
	 * @return GroupLessonDTO[]
	 */
	public function listRecentlyOpened( string $since, string $until ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM %i WHERE visibility = 'hidden' AND scheduled_at > %s AND scheduled_at <= %s",
				$this->table,
				$since,
				$until
			),
			ARRAY_A
		);
		return array_map( [ GroupLessonDTO::class, 'fromArray' ], $rows ?: array() );
	}

	/**
	 * Открытые (видимые ученику) занятия с каким-либо дедлайном — кандидаты cron-продюсера
	 * «дедлайн скоро/просрочен» ({@see \Inc\Services\Profile\NotificationCronService}).
	 * Per-work разбор (какие именно работы и какой у них эффективный дедлайн) — на сервисе.
	 *
	 * @return GroupLessonDTO[]
	 */
	public function listWithDeadlines(): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM %i WHERE visibility = 'open' AND ( homework_due_at IS NOT NULL OR work_deadlines IS NOT NULL )",
				$this->table
			),
			ARRAY_A
		);
		return array_map( [ GroupLessonDTO::class, 'fromArray' ], $rows ?: array() );
	}
}
