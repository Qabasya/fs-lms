<?php

declare( strict_types=1 );

namespace Inc\Services\Group;

use Inc\Enums\Course\AccessMode;
use Inc\Enums\Wp\PageRoutes;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\RoomRepository;
use Inc\Services\Course\EffectiveTeacherResolver;
use Inc\Services\Course\RoomAvailabilityService;

/**
 * Class GroupCalendarService
 *
 * Представление КТП группы для фронта: темы с датами, кабинетами, преподавателями
 * и состоянием публикации + подбор свободных кабинетов под слот.
 *
 * @package Inc\Services\Group
 *
 * Только чтение: изменяют программу {@see ProgramCompositionService} и
 * {@see ScheduleReflowService}.
 */
readonly class GroupCalendarService {

	/**
	 * @param GroupsRepository          $groups           Группы
	 * @param RoomRepository            $rooms            Кабинеты
	 * @param RoomAvailabilityService   $roomAvailability Занятость кабинетов
	 * @param SessionCalendarService    $calendar         Метаданные периода и слотов
	 * @param EffectiveTeacherResolver  $effectiveTeacher Эффективный преподаватель занятия
	 * @param ProgramCompositionService $program          Состав и нумерация тем
	 */
	public function __construct(
		private GroupsRepository          $groups,
		private RoomRepository            $rooms,
		private RoomAvailabilityService   $roomAvailability,
		private SessionCalendarService    $calendar,
		private EffectiveTeacherResolver  $effectiveTeacher,
		private ProgramCompositionService $program,
	) {}

	/**
	 * Свободные кабинеты для группы в окне занятия (T11.3): фильтр по предмету группы
	 * + отсутствие конфликта времени. Конец окна — `$end` или +60 мин от начала.
	 *
	 * @param string      $start Начало окна 'Y-m-d H:i:s'
	 * @param string|null $end   Конец окна (опц.)
	 *
	 * @return array<int, array{id:int, name:string}>
	 */
	public function freeRoomsForGroup( int $groupId, string $start, ?string $end = null ): array {
		$group = $this->groups->findById( $groupId );
		if ( ! $group ) {
			return array();
		}

		$endWindow = ( $end && '' !== $end )
			? $end
			: ( new \DateTimeImmutable( $start ) )->modify( '+60 minutes' )->format( 'Y-m-d H:i:s' );

		return array_map(
			static fn( $room ): array => array( 'id' => $room->id, 'name' => $room->name ),
			$this->roomAvailability->listFreeRooms( $start, $endWindow, (string) $group->subject_key )
		);
	}

	/**
	 * Календарь КТП группы: метаданные периода (даты занятий, выходные) + темы
	 * программы с их размещением. Если курс группе не назначен — assigned=false.
	 *
	 * @param int $groupId ID группы
	 *
	 * @return array{assigned:bool, period:?array, holidays:string[], lessonDays:string[], lessonTimes:array<string,string>, themes:array<int,array<string,mixed>>}
	 */
	public function getCalendar( int $groupId ): array {
		$group = $this->groups->findById( $groupId );
		$meta  = $this->calendar->periodMeta( $groupId );

		// Эффективный кабинет темы (T11.2): кабинет занятия ?? основной кабинет группы.
		$groupRoomId = ( $group && ! empty( $group->room_id ) ) ? (int) $group->room_id : 0;
		$roomNames   = array();
		foreach ( $this->rooms->findAll() as $r ) {
			$roomNames[ $r->id ] = $r->name;
		}

		$entries = $this->program->numberThemes( $this->program->getProgram( $groupId ) );

		$themes       = array();
		$teacherNames = array(); // кэш id→display_name.
		foreach ( $entries as $entry ) {
			$row       = $entry['row'];
			$effRoomId = ! empty( $row->roomId ) ? (int) $row->roomId : $groupRoomId;
			// #16: эффективный преподаватель занятия (Эпик 5): override › замена › препод группы.
			$teacherId = $this->effectiveTeacher->forLesson( $row );
			if ( $teacherId && ! isset( $teacherNames[ $teacherId ] ) ) {
				$teacherNames[ $teacherId ] = get_userdata( $teacherId )->display_name ?? '';
			}
			// Вход в плеер курса (Этап 2, ★): урок с контентом (lesson_id) получает
			// ссылку в плеер teacher-режима — как player_url у ученика (LearnerService).
			$hasContent = null !== $row->lessonId && 0 !== $row->lessonId;
			$themes[]   = array(
				'group_lesson_id' => $row->id,
				'lesson_id'       => $row->lessonId,
				'n'               => $entry['n'],
				// T12.6 (D14): часть темы (1/2, 2/2) — origin+continuation считаются одной темой.
				'part'            => $entry['part'],
				'total_parts'     => $entry['totalParts'],
				'topic'           => $entry['topic'],
				'scheduled_at'    => $row->scheduledAt,
				'is_pinned'       => $row->isPinned,
				'room'            => ( $effRoomId && isset( $roomNames[ $effRoomId ] ) ) ? $roomNames[ $effRoomId ] : '',
				'teacher'         => $teacherId ? ( $teacherNames[ $teacherId ] ?? '' ) : '',
				// Индикатор записи занятия в КТП (модуль VideoLibrary или ручная ссылка).
				'recording_url'   => $row->recordingUrl,
				'status'          => $row->status,
				'player_url'      => $hasContent ? PageRoutes::GroupCockpit->lessonUrl( $groupId, $row->id ) : '',
			);
		}

		return array(
			'assigned'      => $group ? ! empty( $group->course_id ) : false,
			// Эпик 15: открытая группа — расписание не ведётся, фронт показывает
			// программу списком вместо КТП-доски (reflow/publish неприменимы).
			'open'          => $group && AccessMode::Open === AccessMode::fromValueOrDefault( (string) ( $group->access_mode ?? '' ) ),
			// Graceful absence (V4): фильтр вешает VideoLibraryController только при
			// включённом модуле и заполненных S3-ключах — ядро модуль не импортирует,
			// а просто спрашивает, подписался ли кто-то. Гейтит иконку/попап записи в КТП:
			// выключен модуль → фронт ведёт себя так, будто про записи занятий вообще не знает.
			'video_enabled' => has_filter( 'fs_lms_recording_url' ),
			'period'        => $meta['period'],
			'holidays'      => $meta['holidays'],
			'lessonDays'    => $meta['lessonDays'],
			// T12.4: время занятия по дате ('16:00–17:30') для ячейки календаря КТП.
			'lessonTimes'   => $meta['lessonTimes'],
			'themes'        => $themes,
			// T1.8: заблокирована ли КТП (опубликована) — фронт скрывает правки.
			'locked'        => $group ? ! empty( $group->program_locked_at ) : false,
			'locked_at'     => $group && ! empty( $group->program_locked_at ) ? (string) $group->program_locked_at : null,
		);
	}
}
