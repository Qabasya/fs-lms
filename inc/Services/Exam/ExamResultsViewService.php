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
use Inc\Enums\Exam\ExamTokenPurpose;
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
		private readonly ExamParticipantProfileService $profiles,
		private readonly ExamAccessTokenService $tokens,
	) {}

	/**
	 * Раздел «Результаты» (8.7): работы проведений предмета с фильтрами; только проведения, доступные пользователю.
	 *
	 * @param array{event_id?: int, session_id?: int, status?: string, audience?: string, source_id?: int, group_id?: int, school?: string} $filters
	 *        `status` — `pending_review` | `ready` | `approved` | `all`; `audience` — `student` | `guest` | `all`;
	 *        `group_id` — группа наших учеников, `source_id` — источник гостей, `school` — школа (все).
	 *        Группа и источник действуют каждый на своих участников: заданы оба — показываются ученики группы и гости источника,
	 *        задан один — только его участники.
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
		$groupId  = (int) ( $filters['group_id'] ?? 0 );
		$session  = (int) ( $filters['session_id'] ?? 0 );
		$school   = trim( (string) ( $filters['school'] ?? '' ) );

		$items   = array();
		$groups  = array();
		$schools = array();
		foreach ( $selected as $event ) {
			foreach ( $this->resultRows( $event ) as $row ) {
				// Варианты фильтров — по всем строкам выбранных проведений, до применения самих фильтров.
				if ( $row['group_id'] > 0 ) {
					$groups[ $row['group_id'] ] = $row['group'];
				}
				if ( '' !== $row['school'] ) {
					$schools[ $row['school'] ] = $row['school'];
				}

				if ( ( 'all' !== $status && $row['result_status'] !== $status )
					|| ( 'all' !== $audience && $row['audience'] !== $audience )
					|| ( $session > 0 && $row['session_id'] !== $session )
					|| ( '' !== $school && $row['school'] !== $school )
					|| ! $this->matchesParticipantFilters( $row, $groupId, $sourceId ) ) {
					continue;
				}
				$items[] = $row;
			}
		}

		asort( $groups );
		natcasesort( $schools );

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
				'groups'   => array_map(
					static fn ( int $id, string $name ): array => array(
						'id'    => $id,
						'label' => $name,
					),
					array_keys( $groups ),
					array_values( $groups )
				),
				'schools'  => array_values( $schools ),
			),
			'items'   => $items,
		);
	}

	/**
	 * Фильтры «группа» (наши ученики) и «источник» (гости): каждый относится к своим участникам.
	 * Ничего не задано — строка проходит; задан один — подходят только его участники; заданы оба — любой из двух.
	 *
	 * @param array<string, mixed> $row
	 */
	private function matchesParticipantFilters( array $row, int $groupId, int $sourceId ): bool {
		if ( $groupId <= 0 && $sourceId <= 0 ) {
			return true;
		}

		return ( $groupId > 0 && 'student' === $row['audience'] && $row['group_id'] === $groupId )
			|| ( $sourceId > 0 && 'guest' === $row['audience'] && $row['source_id'] === $sourceId );
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

		// «Результат отправлен» — у гостей, которым сотрудник отметил передачу ссылки результата (одним запросом на проведение).
		$guestIds = array_map(
			static fn ( ExamParticipationDTO $p ): int => $p->id,
			array_filter( $participations, static fn ( ExamParticipationDTO $p ): bool => ExamAudience::Guest->value === $p->audience )
		);
		$sent     = $this->tokens->passedTargets( ExamTokenPurpose::Result, array_values( $guestIds ) );

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
			$group        = $this->profiles->group( $participant );

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
				// Школа для таблицы: нашего ученика — из записи, гостя — из ссылки преподавателя, самозаписанного — пусто (прочерк).
				'school'              => $this->profiles->school( $participation, $participant ),
				'source_id'           => $participation->sourceId ?? 0,
				'group_id'            => $group['id'],
				'group'               => $group['name'],
				'result_status'       => $result->value,
				'result_status_label' => $result->label( $audience, isset( $sent[ $participation->id ] ) ),
				'score'               => AttemptStatus::InProgress === $attempt->status ? null : $this->scores->summarize( $attempt, $event ),
			);
		}

		return $rows;
	}
}
