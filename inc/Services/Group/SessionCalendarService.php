<?php

declare( strict_types=1 );

namespace Inc\Services\Group;

use Inc\Enums\Course\LessonStatus;
use Inc\Repositories\OptionsRepositories\AcademicPeriodRepository;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\RoomRepository;
use Inc\Shared\PluginLogger;

/**
 * Генерирует упорядоченные слоты-занятия из повторяющегося расписания группы.
 *
 * Алгоритм: разворот group.meetings[] × [period.start..period.end] − holidays[]
 * → datetime-слоты по порядку; `is_pinned` строки не сдвигаются при reflow.
 */
class SessionCalendarService {

	public function __construct(
		private readonly GroupsRepository        $groups,
		private readonly GroupLessonRepository   $groupLessons,
		private readonly AcademicPeriodRepository $periods,
		private readonly RoomRepository          $rooms,
	) {}

	/**
	 * Генерирует слоты занятий для группы.
	 *
	 * @param int $groupId
	 * @return array{scheduled_at:string,ends_at:string,room:int}[]  — UTC datetime строки + кабинет дня
	 */
	public function generate( int $groupId ): array {
		$group = $this->groups->findById( $groupId );
		if ( ! $group ) {
			return array();
		}

		$meetings = $this->groups->getMeetings( $groupId );
		if ( empty( $meetings ) ) {
			return array();
		}

		$period = $this->periods->getById( (string) $group->academic_period_id );
		if ( ! $period || ! $period->start_date || ! $period->end_date ) {
			return array();
		}

		$holidays = array_flip( $period->holidays );
		$slots    = array();

		$start = new \DateTimeImmutable( $period->start_date . ' 00:00:00' );
		$end   = new \DateTimeImmutable( $period->end_date . ' 23:59:59' );

		// ISO weekday: 1=Mon … 7=Sun  (PHP N format)
		foreach ( $meetings as $meeting ) {
			$weekday     = (int) ( $meeting['weekday'] ?? 0 );
			$time        = (string) ( $meeting['time'] ?? '09:00' );
			$durationMin = (int) ( $meeting['duration_min'] ?? 60 );
			$roomId      = (int) ( $meeting['room'] ?? 0 );

			if ( $weekday < 1 || $weekday > 7 ) {
				continue;
			}

			$cursor = $start;
			while ( $cursor <= $end ) {
				if ( (int) $cursor->format( 'N' ) === $weekday ) {
					$dateStr = $cursor->format( 'Y-m-d' );
					if ( ! isset( $holidays[ $dateStr ] ) ) {
						$scheduledAt = $dateStr . ' ' . $time . ':00';
						$endsAt      = ( new \DateTimeImmutable( $scheduledAt ) )
							->modify( "+{$durationMin} minutes" )
							->format( 'Y-m-d H:i:s' );
						$slots[] = array(
							'scheduled_at' => $scheduledAt,
							'ends_at'      => $endsAt,
							'room'         => $roomId,
						);
					}
				}
				$cursor = $cursor->modify( '+1 day' );
			}
		}

		usort( $slots, static fn( $a, $b ) => strcmp( $a['scheduled_at'], $b['scheduled_at'] ) );

		return $slots;
	}

	/**
	 * Перераспределяет даты уроков группы по сгенерированным слотам.
	 * Пинованные строки (`is_pinned=1`) не сдвигаются.
	 * Хвост уроков, выходящих за период, логируется как warning.
	 *
	 * @param int $groupId
	 */
	public function reflow( int $groupId ): int {
		$slots = $this->generate( $groupId );
		$rows  = $this->groupLessons->listByGroup( $groupId );

		// Слот последовательности потребляют только групповые непиннутые занятия,
		// НЕ освобождающие слот (scheduled + held); отменённые/перенесённые и индивидуальные — нет (T11.6).
		$consuming = array_filter(
			$rows,
			static fn( $r ) => ! $r->isPinned
				&& 'individual' !== $r->kind
				&& ! LessonStatus::fromValueOrDefault( $r->status )->freesSlot()
		);

		if ( count( $consuming ) > count( $slots ) ) {
			PluginLogger::warning(
				'SessionCalendarService',
				'Слотов меньше, чем уроков в программе — хвост останется без даты.',
				array( 'group_id' => $groupId, 'lessons' => count( $consuming ), 'slots' => count( $slots ) )
			);
		}

		// T11.4: если кабинет слота занят ДРУГОЙ группой в это время — снимаем его
		// (исключаем свою группу, чтобы не считать собственные переносимые занятия).
		$conflicts = 0;
		foreach ( $slots as $i => $slot ) {
			$roomId = (int) ( $slot['room'] ?? 0 );
			if ( $roomId <= 0 ) {
				continue;
			}
			if ( $this->rooms->isBusy( $roomId, $slot['scheduled_at'], $slot['ends_at'], 0, $groupId ) ) {
				$slots[ $i ]['room'] = 0;
				++$conflicts;
			}
		}
		if ( $conflicts > 0 ) {
			PluginLogger::warning(
				'SessionCalendarService',
				'Кабинет занят другой группой — снят с занятия при раскладке.',
				array( 'group_id' => $groupId, 'conflicts' => $conflicts )
			);
		}

		$this->groupLessons->applySlots( $groupId, $slots );

		return $conflicts;
	}

	/**
	 * Метаданные периода для рендера календаря КТП: границы периода, выходные
	 * и уникальные даты занятий (из сгенерированных слотов).
	 *
	 * @return array{period:?array{start_date:string,end_date:string}, holidays:string[], lessonDays:string[]}
	 */
	public function periodMeta( int $groupId ): array {
		$group = $this->groups->findById( $groupId );
		if ( ! $group ) {
			return array( 'period' => null, 'holidays' => array(), 'lessonDays' => array() );
		}

		$period     = $this->periods->getById( (string) $group->academic_period_id );
		$lessonDays = array_values( array_unique( array_map(
			static fn( array $slot ): string => substr( $slot['scheduled_at'], 0, 10 ),
			$this->generate( $groupId )
		) ) );

		return array(
			'period'     => $period && $period->start_date && $period->end_date
				? array( 'start_date' => $period->start_date, 'end_date' => $period->end_date )
				: null,
			'holidays'   => $period ? array_values( $period->holidays ) : array(),
			'lessonDays' => $lessonDays,
		);
	}
}
