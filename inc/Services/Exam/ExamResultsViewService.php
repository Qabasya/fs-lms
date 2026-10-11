<?php
/**
 * Список результатов для сотрудника: чтение, фильтры и доступ к проведению.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\DTO\Exam\ExamEventDTO;
use Inc\DTO\Exam\ExamParticipationDTO;
use Inc\Enums\Assessment\AttemptStatus;
use Inc\Enums\Exam\ExamAudience;
use Inc\Enums\Exam\ExamEventStatus;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Repositories\WPDBRepositories\ExamRegistrationRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipantRepository;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\ExamSourceRepository;

/** Список результатов для сотрудника: чтение, фильтры и доступ к проведению. */
class ExamResultsViewService {

	/** Получает данные результатов, общие имена и состояния отдельно от команд проведения. */
	public function __construct(
		private readonly ExamEventRepository $events,
		private readonly ExamSessionRepository $sessions,
		private readonly ExamRegistrationRepository $registrations,
		private readonly ExamParticipationRepository $participations,
		private readonly ExamParticipantRepository $participants,
		private readonly AssessmentAttemptRepository $attempts,
		private readonly ExamSourceRepository $sources,
		private readonly ExamParticipantNameService $names,
		private readonly ExamScoreService $scores,
		private readonly ExamAccessGuard $guard,
		private readonly ExamTime $time,
		private readonly ExamResultStatusService $resultStates,
		private readonly ExamEventViewService $eventView,
	) {}

	/**
	 * Раздел «Результаты» (8.7): работы проведений предмета с фильтрами; только проведения, доступные пользователю.
	 *
	 * @param array{event_id?: int, session_id?: int, status?: string, audience?: string, source_id?: int} $filters
	 *        `status` — `pending_review` | `ready` | `approved` | `all`; `audience` — `student` | `guest` | `all`.
	 *
	 * @return array{filters: array<string, mixed>, items: list<array<string, mixed>>}
	 */
	public function results( int $actorUserId, string $subjectKey, array $filters ): array {
		$events = array_values(
			array_filter(
				$this->events->findBySubjectKey( $subjectKey ),
				fn ( ExamEventDTO $event ): bool => in_array( $event->status, array( ExamEventStatus::Published->value, ExamEventStatus::Completed->value ), true )
					&& $this->guard->canManageEvent( $actorUserId, $event )
			)
		);

		$eventId  = (int) ( $filters['event_id'] ?? 0 );
		$selected = $eventId > 0
			? array_values( array_filter( $events, static fn ( ExamEventDTO $e ): bool => $e->id === $eventId ) )
			: $events;

		$status   = (string) ( $filters['status'] ?? 'all' );
		$audience = (string) ( $filters['audience'] ?? 'all' );
		$sourceId = (int) ( $filters['source_id'] ?? 0 );
		$session  = (int) ( $filters['session_id'] ?? 0 );

		$items = array();
		foreach ( $selected as $event ) {
			foreach ( $this->resultRows( $event ) as $row ) {
				if ( ( 'all' !== $status && $row['result_status'] !== $status )
					|| ( 'all' !== $audience && $row['audience'] !== $audience )
					|| ( $sourceId > 0 && $row['source_id'] !== $sourceId )
					|| ( $session > 0 && $row['session_id'] !== $session ) ) {
					continue;
				}
				$items[] = $row;
			}
		}

		return array(
			'filters' => array(
				'events'   => array_map(
					static fn ( ExamEventDTO $e ): array => array(
						'id'    => $e->id,
						'title' => $e->title,
					),
					$events
				),
				'sessions' => 1 === count( $selected ) ? $this->eventView->siblings( $selected[0] ) : array(),
				'sources'  => 1 === count( $selected ) ? $this->eventView->sourceOptions( $selected[0]->id ) : array(),
			),
			'items'   => $items,
		);
	}

	/**
	 * Строки результатов одного проведения: по одной на участие с попыткой.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function resultRows( ExamEventDTO $event ): array {
		$participations = array_filter( $this->participations->findByEvent( $event->id ), static fn ( ExamParticipationDTO $p ): bool => $p->hasAttempt() );
		if ( array() === $participations ) {
			return array();
		}

		$attempts = array();
		foreach ( $this->attempts->listByParticipations( array_map( static fn ( ExamParticipationDTO $p ): int => $p->id, $participations ) ) as $attempt ) {
			$attempts[ (int) $attempt->examParticipationId ] = $attempt;
		}

		$rows = array();
		foreach ( $participations as $participation ) {
			$attempt     = $attempts[ $participation->id ] ?? null;
			$participant = $this->participants->find( $participation->participantId );
			if ( null === $attempt || null === $participant ) {
				continue;
			}

			$registration = null !== $attempt->examRegistrationId ? $this->registrations->find( $attempt->examRegistrationId ) : null;
			$session      = null !== $registration ? $this->sessions->find( $registration->sessionId ) : null;
			$audience     = ExamAudience::from( $participation->audience );
			$result       = $this->resultStates->status( $attempt, $audience );
			$source       = null !== $participation->sourceId ? $this->sources->find( $participation->sourceId ) : null;
			$start        = null !== $session ? $this->time->toLocal( $session->scheduledAt ) : null;

			$rows[] = array(
				'attempt_id'          => $attempt->id,
				'participation_id'    => $participation->id,
				'result_version'      => $attempt->resultVersion,
				'event_id'            => $event->id,
				'event_title'         => $event->title,
				'session_id'          => $session->id ?? 0,
				'session_date'        => null !== $start ? substr( $start, 0, 10 ) : '',
				'session_time'        => null !== $start ? substr( $start, 11, 5 ) : '',
				'name'                => $this->names->participantName( $participant ),
				'audience'            => $audience->value,
				'audience_label'      => $audience->label(),
				'source'              => $source->schoolName ?? '',
				'source_id'           => $participation->sourceId ?? 0,
				'result_status'       => $result->value,
				'result_status_label' => $result->label( $audience ),
				'score'               => AttemptStatus::InProgress === $attempt->status ? null : $this->scores->summarize( $attempt, $event ),
			);
		}

		return $rows;
	}
}
