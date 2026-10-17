<?php
/**
 * Варианты фильтров проведения для доски и списка результатов.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\DTO\Exam\ExamEventDTO;
use Inc\Enums\Exam\ExamSessionStatus;
use Inc\Repositories\WPDBRepositories\ExamRegistrationRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Repositories\WPDBRepositories\ExamSourceRepository;

/** Варианты фильтров проведения для доски и списка результатов. */
class ExamEventViewService {

	/** Получает сеансы и источники для общих сводок и фильтров проведения. */
	public function __construct(
		private readonly ExamSessionRepository $sessions,
		private readonly ExamSourceRepository $sources,
		private readonly ExamRegistrationRepository $registrations,
		private readonly ExamTime $time,
	) {}

	/**
	 * Готовит варианты источника гостей для фильтра выбранного проведения.
	 *
	 * @return list<array{id: int, label: string}>
	 */
	public function sourceOptions( int $eventId ): array {
		return array_map(
			static fn ( $source ): array => array(
				'id'    => $source->id,
				'label' => $source->schoolName . ' · ' . $source->grade . ' класс',
			),
			$this->sources->listByEvent( $eventId )
		);
	}

	/**
	 * Сеансы проведения для переключателя.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function siblings( ExamEventDTO $event ): array {
		$list   = array();
		$nowUtc = $this->time->nowUtc();
		foreach ( $this->sessions->findByEvent( $event->id ) as $session ) {
			if ( ExamSessionStatus::Cancelled->value === $session->status ) {
				continue;
			}
			$start  = $this->time->toLocal( $session->scheduledAt );
			$list[] = array(
				'id'         => $session->id,
				'date'       => substr( $start, 0, 10 ),
				'time_start' => substr( $start, 11, 5 ),
				'free'       => $session->freeSeats(),
				// Действующие записи сеанса — подпись в переключателе доски.
				'registered' => $this->registrations->countActiveBySession( $session->id ),
				'status'     => $session->status,
				// Сеанс уже закончился: переключатель его показывает, но переносить на него записи нельзя.
				'is_past'    => $session->plannedEndAt <= $nowUtc,
			);
		}

		return $list;
	}
}
