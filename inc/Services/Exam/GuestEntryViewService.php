<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\DTO\Exam\AttemptContext;
use Inc\Enums\Assessment\AttemptStatus;
use Inc\Enums\Exam\ExamSessionStatus;
use Inc\Enums\Wp\PageRoutes;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipantRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Repositories\WPDBRepositories\ExamRegistrationRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Services\Shared\CenterContactsService;

/**
 * Данные страницы входа гостя (11b.1.6): имя, проведение, окно старта и одна кнопка по моменту.
 *
 * Состояния: `before` (вход ещё закрыт), `open` («Приступить»), `in_progress` («Продолжить»), `submitted` («Посмотреть результат»),
 * `expired` (старт не состоялся до планового конца). Открытие страницы таймер не запускает — попытку начинает только станция.
 */
class GuestEntryViewService {

	public function __construct(
		private readonly ExamParticipationRepository $participations,
		private readonly ExamParticipantRepository $participants,
		private readonly ExamRegistrationRepository $registrations,
		private readonly ExamSessionRepository $sessions,
		private readonly ExamEventRepository $events,
		private readonly AssessmentAttemptRepository $attempts,
		private readonly AssessmentManager $assessments,
		private readonly GuestParticipantMaterializer $guestData,
		private readonly CenterContactsService $contacts,
		private readonly ExamTime $time,
	) {}

	/**
	 * @return array{state: string, name: string, event_title: string, date: string, window_from: string, window_to: string, deadline: string, action_url: string, contacts: array<string, string>}|null
	 */
	public function build( AttemptContext $ctx ): ?array {
		$participation = $this->participations->find( $ctx->participationId );
		$registration  = $this->registrations->find( $ctx->registrationId );
		$session       = null !== $registration ? $this->sessions->find( $registration->sessionId ) : null;
		$event         = null !== $session ? $this->events->find( $session->eventId ) : null;
		$participant   = null !== $participation ? $this->participants->find( $participation->participantId ) : null;
		if ( null === $participation || null === $registration || null === $session || null === $event || null === $participant ) {
			return null;
		}

		$attempt = null !== $participation->currentAttemptId ? $this->attempts->find( $participation->currentAttemptId ) : null;
		$nowUtc  = $this->time->nowUtc();
		$url     = '';

		if ( null !== $attempt && AttemptStatus::InProgress !== $attempt->status ) {
			$state = 'submitted';
			$url   = PageRoutes::ExamResult->url();
		} elseif ( null !== $attempt ) {
			$state = 'in_progress';
			$url   = $this->assessments->examStationUrl( $attempt->assessmentId, $registration->id );
		} elseif ( ExamSessionStatus::Cancelled->value === $session->status || $nowUtc >= $session->plannedEndAt ) {
			$state = 'expired';
		} elseif ( $nowUtc < $session->scheduledAt ) {
			$state = 'before';
		} else {
			$state = 'open';
			$url   = $this->assessments->examStationUrl( $session->assessmentId, $registration->id );
		}

		$from = $this->time->toLocal( $session->scheduledAt );
		$to   = $this->time->toLocal( $session->plannedEndAt );

		return array(
			'state'       => $state,
			'name'        => $this->guestData->displayName( $participant ) ?? '',
			'event_title' => $event->title,
			'date'        => substr( $from, 0, 10 ),
			'window_from' => substr( $from, 11, 5 ),
			'window_to'   => substr( $to, 11, 5 ),
			'deadline'    => null !== $attempt && AttemptStatus::InProgress === $attempt->status ? substr( $attempt->deadlineAt, 11, 5 ) : '',
			'action_url'  => $url,
			'contacts'    => $this->contacts->get(),
		);
	}
}
