<?php

declare( strict_types=1 );

namespace Inc\Services\Group;

use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Services\Course\RoomAvailabilityService;

/**
 * Class ScheduleReflowService
 *
 * Даты КТП: постановка занятия на дату, закрепление (pin) и переразливка
 * непиннутых тем по слотам периода.
 *
 * @package Inc\Services\Group
 *
 * Состав программы (какие темы и в каком порядке) — {@see ProgramCompositionService};
 * здесь только «когда».
 */
readonly class ScheduleReflowService {

	/**
	 * @param GroupLessonRepository   $groupLessons     Строки программы
	 * @param GroupsRepository        $groups           Группы
	 * @param SessionCalendarService  $calendar         Раскладка по слотам периода
	 * @param RoomAvailabilityService $roomAvailability Занятость кабинетов
	 * @param ScheduleEventPublisher  $events           Публикация событий обучения
	 */
	public function __construct(
		private GroupLessonRepository   $groupLessons,
		private GroupsRepository        $groups,
		private SessionCalendarService  $calendar,
		private RoomAvailabilityService $roomAvailability,
		private ScheduleEventPublisher  $events,
	) {}

	/**
	 * Ставит строку программы на дату (и, опционально, назначает преподавателя).
	 *
	 * @param int         $groupLessonId ID строки программы
	 * @param string|null $scheduledAt   'Y-m-d H:i:s' или null (снять дату)
	 * @param int|null    $teacherUserId Преподаватель занятия
	 * @param int         $actorUserId   Автор изменения
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException Если строка не найдена
	 */
	public function schedule( int $groupLessonId, ?string $scheduledAt, ?int $teacherUserId, int $actorUserId ): void {
		$row = $this->requireRow( $groupLessonId );

		$this->groupLessons->updateSchedule( $groupLessonId, $scheduledAt, $teacherUserId );
		$this->events->lessonChanged( $row->groupId, $groupLessonId, $actorUserId );
	}

	/**
	 * Закрепляет/освобождает строку: пиннутая дата не сдвигается reflow.
	 *
	 * @param int  $groupLessonId ID строки программы
	 * @param bool $pinned        Закрепить или снять закрепление
	 * @param int  $actorUserId   Автор изменения
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException Если строка не найдена
	 */
	public function pin( int $groupLessonId, bool $pinned, int $actorUserId ): void {
		$row = $this->requireRow( $groupLessonId );

		$this->groupLessons->setPinned( $groupLessonId, $pinned );
		$this->events->lessonChanged( $row->groupId, $groupLessonId, $actorUserId );
	}

	/**
	 * Переразливает непиннутые темы по слотам периода.
	 *
	 * @param int $groupId     ID группы
	 * @param int $actorUserId Автор изменения
	 *
	 * @return int Количество конфликтов, обнаруженных при раскладке
	 */
	public function reflow( int $groupId, int $actorUserId ): int {
		$conflicts = $this->calendar->reflow( $groupId );
		$this->events->groupChanged( $groupId, $actorUserId );

		return $conflicts;
	}

	/**
	 * Отменяет распределение: снимает даты/закрепление со всех непроведённых
	 * групповых занятий — темы возвращаются в пул «Темы курса».
	 *
	 * @param int $groupId     ID группы
	 * @param int $actorUserId Автор изменения
	 *
	 * @return int Количество затронутых строк.
	 */
	public function unschedule( int $groupId, int $actorUserId ): int {
		$affected = $this->groupLessons->unscheduleAll( $groupId );
		$this->events->groupChanged( $groupId, $actorUserId );

		return $affected;
	}

	/**
	 * Закрепляет тему на конкретную дату (drag-drop в КТП): дата + pin, затем
	 * остальные (непиннутые) темы переразливаются вокруг закреплённой.
	 *
	 * @param int    $groupLessonId ID строки программы
	 * @param string $scheduledAt   Дата/датавремя слота ('Y-m-d' или 'Y-m-d H:i:s')
	 * @param int    $actorUserId   Автор изменения
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException Если строка не найдена или кабинет занят
	 */
	public function pinToDate( int $groupLessonId, string $scheduledAt, int $actorUserId ): void {
		$row = $this->requireRow( $groupLessonId );

		$this->assertRoomFree( $row, $scheduledAt, $groupLessonId );

		$this->groupLessons->updateSchedule( $groupLessonId, $scheduledAt, $row->teacherUserId );
		$this->groupLessons->setPinned( $groupLessonId, true );
		$this->calendar->reflow( $row->groupId );

		$this->events->lessonChanged( $row->groupId, $groupLessonId, $actorUserId );
	}

	/**
	 * Конфликт кабинета (T11.4): эффективный кабинет занятия ?? основной кабинет
	 * группы; hard-block, если он занят ДРУГОЙ группой в это время. Занятия своей
	 * группы (T12.5: две темы на один день) конфликтом не считаются — аналогично reflow.
	 *
	 * @param \Inc\DTO\Course\GroupLessonDTO $row           Строка программы
	 * @param string                         $scheduledAt   Планируемое начало
	 * @param int                            $groupLessonId ID строки (исключается из проверки)
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException Если кабинет занят
	 */
	private function assertRoomFree( \Inc\DTO\Course\GroupLessonDTO $row, string $scheduledAt, int $groupLessonId ): void {
		$group  = $this->groups->findById( $row->groupId );
		$roomId = ! empty( $row->roomId )
			? (int) $row->roomId
			: ( ( $group && ! empty( $group->room_id ) ) ? (int) $group->room_id : 0 );

		if ( $roomId <= 0 ) {
			return;
		}

		$end = ( $row->endsAt && '' !== $row->endsAt )
			? $row->endsAt
			: ( new \DateTimeImmutable( $scheduledAt ) )->modify( '+60 minutes' )->format( 'Y-m-d H:i:s' );

		if ( ! $this->roomAvailability->isFree( $roomId, $scheduledAt, $end, $groupLessonId, $row->groupId ) ) {
			throw new \InvalidArgumentException( 'Кабинет занят в это время другим занятием.' );
		}
	}

	/**
	 * Строка программы или исключение.
	 *
	 * @param int $groupLessonId ID строки
	 *
	 * @throws \InvalidArgumentException Если строка не найдена
	 */
	private function requireRow( int $groupLessonId ): \Inc\DTO\Course\GroupLessonDTO {
		$row = $this->groupLessons->find( $groupLessonId );
		if ( ! $row ) {
			throw new \InvalidArgumentException( 'Строка программы не найдена.' );
		}

		return $row;
	}
}
