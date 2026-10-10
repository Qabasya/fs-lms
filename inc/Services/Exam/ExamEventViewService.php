<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\DTO\Exam\ExamEventDTO;
use Inc\Enums\Exam\ExamSessionStatus;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Repositories\WPDBRepositories\ExamSourceRepository;

/** Варианты фильтров проведения для доски и списка результатов. */
class ExamEventViewService {

	public function __construct(
		private readonly ExamSessionRepository $sessions,
		private readonly ExamSourceRepository $sources,
		private readonly ExamTime $time,
	) {}

	/**
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
		$list = array();
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
				'status'     => $session->status,
			);
		}

		return $list;
	}
}
