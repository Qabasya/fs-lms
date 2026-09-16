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
	 * @param int         $groupLessonId ID строки программы
	 * @param string      $scheduledAt   Дата/датавремя слота ('Y-m-d' или 'Y-m-d H:i:s')
	 * @param int         $actorUserId   Автор изменения
	 * @param string|null $endsAt        Явный конец занятия (Этап 4: выбран автором в
	 *                                   модалке урока вне расписания) — если не передан,
	 *                                   вычисляется автоматически {@see resolveEndsAt()}
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException Если строка не найдена, дата вне периода/выходной,
	 *                                   кабинет занят, время пересекается с индивидуальным
	 *                                   занятием группы в этот день, либо на дате уже стоит
	 *                                   проведённое (`held`) занятие
	 */
	public function pinToDate( int $groupLessonId, string $scheduledAt, int $actorUserId, ?string $endsAt = null ): void {
		$row = $this->requireRow( $groupLessonId );

		$this->assertWithinPeriod( $row->groupId, $scheduledAt );
		$this->assertRoomFree( $row, $scheduledAt, $groupLessonId );

		$endsAt = $endsAt && '' !== $endsAt ? $endsAt : $this->resolveEndsAt( $row->groupId, $scheduledAt );

		$day       = substr( $scheduledAt, 0, 10 );
		$dayRows   = $this->groupLessons->listByGroupAndDay( $row->groupId, $day );
		$displaced = array_values( array_filter(
			$dayRows,
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

		// Индивидуальные занятия того же дня не вытесняются (Этап 3) — но окно времени
		// должно не пересекаться с ними, иначе тема физически накладывается на занятие
		// другого ученика в расписании преподавателя.
		foreach ( $dayRows as $r ) {
			if ( $r->id === $groupLessonId || ! $r->kind->isIndividual() || null === $r->scheduledAt ) {
				continue;
			}
			$rEnd = $r->endsAt ?? $r->scheduledAt;
			if ( $scheduledAt < $rEnd && $endsAt > $r->scheduledAt ) {
				throw new \InvalidArgumentException( 'Время пересекается с индивидуальным занятием группы в этот день.' );
			}
		}

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
	 * Возвращает тему в пул «Темы курса» (drag размещённой темы обратно в банк) и
	 * подтягивает хвост: занятия, стоявшие после снятой даты, сдвигаются на одно
	 * окно вперёд — освободившаяся дата занимается следующей темой, её дата —
	 * темой за ней и так далее. Календарь занятий (какие дни заняты) не меняется,
	 * меняется только то, какая тема на каком дне.
	 *
	 * Сдвиг идёт до первого якоря — проведённого (`held`), закреплённого вручную
	 * (`is_pinned`) или отменённого/перенесённого занятия: дальше него хвост не
	 * едет, иначе тема перепрыгнула бы якорь по дате. Индивидуальные занятия в
	 * сдвиге не участвуют вовсе — у них своя дата, к последовательности курса
	 * они не относятся.
	 *
	 * Здесь НЕ годится `reflow()`: `applySlots()` раскладывает все непиннутые
	 * строки по `position` с начала периода и вернул бы дату строке, которую мы
	 * только что отправили в пул.
	 *
	 * @param int $groupLessonId ID строки программы
	 * @param int $actorUserId   Автор изменения
	 *
	 * @return int Сколько занятий сдвинулось следом.
	 *
	 * @throws \InvalidArgumentException Если строка не найдена или занятие уже проведено
	 */
	public function returnToPool( int $groupLessonId, int $actorUserId ): int {
		$row = $this->requireRow( $groupLessonId );

		if ( LessonStatus::Held === LessonStatus::fromValueOrDefault( $row->status ) ) {
			throw new \InvalidArgumentException( 'Проведённое занятие нельзя вернуть в пул — это исторический факт.' );
		}

		if ( null === $row->scheduledAt ) {
			return 0;
		}

		$allRows   = $this->groupLessons->listByGroup( $row->groupId );
		$freeSlots = array( $this->slotOf( $row ) );

		$this->groupLessons->clearSchedule( $groupLessonId );

		// T12.6: вторая часть темы без первой висела бы в календаре разрозненно —
		// продолжения уходят в пул вместе с оригиналом, их окна тоже освобождаются.
		foreach ( $allRows as $r ) {
			if ( $r->continuedFromId === $groupLessonId && null !== $r->scheduledAt ) {
				$freeSlots[] = $this->slotOf( $r );
				$this->groupLessons->clearSchedule( $r->id );
			}
		}

		usort( $freeSlots, static fn( $a, $b ) => strcmp( $a['scheduled_at'], $b['scheduled_at'] ) );

		// Хвост считаем от самого раннего освободившегося окна: часть темы могла
		// стоять и раньше оригинала, и эта дырка тоже должна закрыться.
		$shifted = $this->shiftTail( $allRows, $freeSlots[0]['scheduled_at'], $freeSlots, $groupLessonId );

		$this->events->lessonChanged( $row->groupId, $groupLessonId, $actorUserId );

		return $shifted;
	}

	/**
	 * Сдвигает занятия, стоящие после освободившейся даты, на одно окно вперёд.
	 * Каждая сдвигаемая строка забирает самое раннее свободное окно и отдаёт в
	 * очередь своё — так «дырка» едет по календарю до конца хвоста.
	 *
	 * Сдвиг останавливается на первом якоре: закреплённом вручную (`is_pinned`),
	 * проведённом (`held`) или отменённом/перенесённом занятии. Иначе тема из-за
	 * якоря перепрыгнула бы его по дате, и порядок курса разошёлся бы с порядком
	 * дат — а закреплённая дата перестала бы что-либо значить.
	 *
	 * @param \Inc\DTO\Course\GroupLessonDTO[]                                     $rows      Все строки группы
	 * @param string                                                               $freedFrom Дата, которая освободилась первой
	 * @param array<int, array{scheduled_at:string, ends_at:?string, room_id:?int}> $freeSlots Стартовая очередь окон
	 * @param int                                                                  $skipId    Строка, ушедшая в пул
	 *
	 * @return int Количество сдвинутых строк.
	 */
	private function shiftTail( array $rows, string $freedFrom, array $freeSlots, int $skipId ): int {
		$tail = array_values( array_filter(
			$rows,
			static fn( $r ) => $r->id !== $skipId
				&& $r->continuedFromId !== $skipId
				&& null !== $r->scheduledAt
				&& $r->scheduledAt > $freedFrom
				&& ! $r->kind->isIndividual()
		) );
		usort( $tail, static fn( $a, $b ) => strcmp( (string) $a->scheduledAt, (string) $b->scheduledAt ) );

		$shifted = 0;
		foreach ( $tail as $r ) {
			$status = LessonStatus::fromValueOrDefault( $r->status );
			if ( $r->isPinned || LessonStatus::Held === $status || $status->freesSlot() ) {
				break;
			}
			if ( array() === $freeSlots ) {
				break;
			}

			$vacated = $this->slotOf( $r );
			$this->groupLessons->moveToSlot( $r->id, array_shift( $freeSlots ) );

			// Очередь обязана оставаться отсортированной: при возврате в пул темы
			// с продолжением освобождается больше одного окна, и только что
			// освобождённое может оказаться раньше уже лежащего в очереди.
			$freeSlots[] = $vacated;
			usort( $freeSlots, static fn( $a, $b ) => strcmp( $a['scheduled_at'], $b['scheduled_at'] ) );

			++$shifted;
		}

		return $shifted;
	}

	/**
	 * Окно занятия строки: начало, конец и кабинет. Кабинет принадлежит дню
	 * расписания, поэтому едет вместе с датой (см. {@see GroupLessonRepository::moveToSlot()}).
	 *
	 * @return array{scheduled_at:string, ends_at:?string, room_id:?int}
	 */
	private function slotOf( \Inc\DTO\Course\GroupLessonDTO $row ): array {
		return array(
			'scheduled_at' => (string) $row->scheduledAt,
			'ends_at'      => $row->endsAt,
			'room_id'      => $row->roomId,
		);
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
	 * Дата закрепления обязана лежать внутри границ учебного периода группы и не
	 * приходиться на выходной (Этап 4: и день без слота — тоже рабочий день периода,
	 * просто без штатной встречи).
	 *
	 * @throws \InvalidArgumentException Периода нет, дата вне его границ, либо день — выходной
	 */
	private function assertWithinPeriod( int $groupId, string $scheduledAt ): void {
		$meta   = $this->calendar->periodMeta( $groupId );
		$period = $meta['period'];
		if ( ! $period ) {
			throw new \InvalidArgumentException( 'У группы не задан учебный период.' );
		}

		$day = substr( $scheduledAt, 0, 10 );
		if ( $day < $period['start_date'] || $day > $period['end_date'] ) {
			throw new \InvalidArgumentException( 'Дата вне границ учебного периода.' );
		}
		if ( in_array( $day, $meta['holidays'], true ) ) {
			throw new \InvalidArgumentException( 'На этот день назначен выходной.' );
		}
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
