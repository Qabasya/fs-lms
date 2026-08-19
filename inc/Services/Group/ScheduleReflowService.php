<?php

declare( strict_types=1 );

namespace Inc\Services\Group;

use Inc\DTO\Course\ScheduleReflowResultDTO;
use Inc\Enums\Course\LessonStatus;
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
	 * @return ScheduleReflowResultDTO Конфликты кабинета + укомплектованность периода
	 */
	public function reflow( int $groupId, int $actorUserId ): ScheduleReflowResultDTO {
		$result = $this->calendar->reflow( $groupId );
		$this->events->groupChanged( $groupId, $actorUserId );

		return $result;
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
	 * Закрепляет тему строго на дату (Этап 3, Tasks.md, решение принято): тема,
	 * которая стояла на этой дате, возвращается в пул «Темы курса» — без даты,
	 * без закрепления. Остальные размещённые темы дат не меняют, никакого
	 * каскадного сдвига (раньше здесь звался `calendar->reflow()`, который
	 * перекладывал ВСЕ непиннутые строки от начала периода — корень бага
	 * «перетащил один урок, съехало всё»). `position` не трогаем: порядок
	 * курса — это порядок тем, а не порядок дат.
	 *
	 * Одна тема на день — правило действует на любом дне, слот там есть или нет
	 * (в т.ч. Этап 4, урок вне расписания).
	 *
	 * @param int    $groupLessonId ID строки программы
	 * @param string $scheduledAt   Дата/датавремя слота ('Y-m-d' или 'Y-m-d H:i:s')
	 * @param int    $actorUserId   Автор изменения
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException Если строка не найдена, кабинет занят, либо
	 *                                   на дате уже стоит проведённое (`held`) занятие
	 */
	public function pinToDate( int $groupLessonId, string $scheduledAt, int $actorUserId ): void {
		$row = $this->requireRow( $groupLessonId );

		$this->assertRoomFree( $row, $scheduledAt, $groupLessonId );

		$day       = substr( $scheduledAt, 0, 10 );
		$displaced = array_values( array_filter(
			$this->groupLessons->listByGroupAndDay( $row->groupId, $day ),
			static fn( $r ) => $r->id !== $groupLessonId && ! $r->kind->isIndividual()
		) );

		foreach ( $displaced as $d ) {
			if ( LessonStatus::Held === LessonStatus::fromValueOrDefault( $d->status ) ) {
				// Проведённое занятие — исторический факт, drop отклоняется целиком:
				// перетаскиваемая тема ничего не получает, чтобы не создать видимость
				// успеха при частичном откате.
				throw new \InvalidArgumentException( 'На эту дату уже поставлено проведённое занятие — заменить его нельзя.' );
			}
		}

		$endsAt = $this->resolveEndsAt( $row->groupId, $scheduledAt );

		$this->groupLessons->updateSchedule( $groupLessonId, $scheduledAt, $row->teacherUserId, $endsAt );
		$this->groupLessons->setPinned( $groupLessonId, true );

		$allRows = null;
		foreach ( $displaced as $d ) {
			$this->groupLessons->clearSchedule( $d->id );

			// T12.6: вытеснение исходной части не должно осиротить вторую — вытесняем
			// обе вместе (продолжение без даты «оригинала» рядом смотрелось бы разрозненно).
			$allRows ??= $this->groupLessons->listByGroup( $row->groupId );
			foreach ( $allRows as $r ) {
				if ( $r->continuedFromId === $d->id && null !== $r->scheduledAt ) {
					$this->groupLessons->clearSchedule( $r->id );
				}
			}
		}

		$this->events->lessonChanged( $row->groupId, $groupLessonId, $actorUserId );
	}

	/**
	 * Конец занятия для даты закрепления: слот периода даёт готовый `ends_at`;
	 * день без слота (Этап 4, урок вне расписания) — начало плюс длительность
	 * встречи того же дня недели (при нескольких встречах в день — первая
	 * подходящая), либо первой встречи группы, если день недели не совпал ни
	 * с одной.
	 */
	private function resolveEndsAt( int $groupId, string $scheduledAt ): string {
		foreach ( $this->calendar->generate( $groupId ) as $slot ) {
			if ( $slot['scheduled_at'] === $scheduledAt ) {
				return $slot['ends_at'];
			}
		}

		$meetings = $this->groups->getMeetings( $groupId );
		$duration = 60;
		if ( ! empty( $meetings ) ) {
			$weekday = (int) ( new \DateTimeImmutable( $scheduledAt ) )->format( 'N' );
			$sameDay = array_values( array_filter(
				$meetings,
				static fn( $m ) => (int) ( $m['weekday'] ?? 0 ) === $weekday
			) );
			$duration = (int) ( ( $sameDay[0] ?? $meetings[0] )['duration_min'] ?? 60 );
		}

		return ( new \DateTimeImmutable( $scheduledAt ) )->modify( "+{$duration} minutes" )->format( 'Y-m-d H:i:s' );
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
