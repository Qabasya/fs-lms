<?php

declare( strict_types=1 );

namespace Inc\Services\Log;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Log\ExamLogInputDTO;
use Inc\Enums\Exam\ExamLogAction;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipantRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Repositories\WPDBRepositories\ExamRegistrationRepository;
use Inc\Repositories\WPDBRepositories\Log\ExamLogRepository;
use Inc\Services\Exam\ExamParticipantNameService;
use Inc\Shared\PluginLogger;
use Inc\Shared\Traits\RequestContextProvider;

/**
 * Class ExamLogWriter
 *
 * Запись действий с экзаменами в журнал «Экзамены».
 *
 * @package Inc\Services\Log
 *
 * Вызывающему коду достаточно передать то, что он знает ({@see ExamLogAction::record()}):
 * писатель достраивает цепочку запись → участие → участник → проведение и подставляет
 * ФИО участника (если ФИО определить нельзя — номер). Сбой записи журнала никогда не
 * прерывает само действие: он уходит только в PHP-лог.
 */
class ExamLogWriter {

	use RequestContextProvider;

	public function __construct(
		private readonly ExamLogRepository          $repository,
		private readonly ClockInterface             $clock,
		private readonly ExamRegistrationRepository $registrations,
		private readonly ExamParticipationRepository $participations,
		private readonly ExamParticipantRepository  $participants,
		private readonly AssessmentAttemptRepository $attempts,
		private readonly ExamParticipantNameService $names,
	) {}

	/**
	 * Пишет действие; любые сбои гасит.
	 *
	 * @param array<string, mixed> $data Данные действия, см. {@see ExamLogAction::record()}
	 */
	public function write( ExamLogAction $action, array $data ): void {
		try {
			$this->repository->create( $this->input( $action, $data ) );
		} catch ( \Throwable $e ) {
			PluginLogger::exception( 'ExamLog', $e, array( 'action' => $action->value ), true );
		}
	}

	/**
	 * Собирает вставку: актор, связи объектов, ФИО участника.
	 *
	 * @param array<string, mixed> $data Данные действия
	 */
	private function input( ExamLogAction $action, array $data ): ExamLogInputDTO {
		$eventId        = $this->id( $data, 'event_id' );
		$sessionId      = $this->id( $data, 'session_id' );
		$registrationId = $this->id( $data, 'registration_id' );
		$attemptId      = $this->id( $data, 'attempt_id' );
		$participationId = $this->id( $data, 'participation_id' );
		$participantId  = $this->id( $data, 'participant_id' );

		if ( null !== $attemptId && null === $participationId ) {
			$participationId = $this->attempts->find( $attemptId )?->examParticipationId;
		}
		if ( null !== $registrationId ) {
			$registration    = $this->registrations->find( $registrationId );
			$participationId ??= $registration?->participationId;
			$sessionId       ??= $registration?->sessionId;
		}
		if ( null !== $participationId ) {
			$participation = $this->participations->find( $participationId );
			$participantId ??= $participation?->participantId;
			$eventId       ??= $participation?->eventId;
		}

		$subject = isset( $data['subject'] ) && '' !== $data['subject'] ? (string) $data['subject'] : $this->subject( $participantId );

		// Актор не назван и тип не задан — это действие пользователя текущего запроса (участник за себя);
		// у крона, тика и гостя (не пользователь WP) вызывающий код задаёт тип явно или актора нет вовсе.
		$explicitType = isset( $data['actor_type'] ) ? (string) $data['actor_type'] : null;
		$actorUserId  = $this->id( $data, 'actor' ) ?? ( null === $explicitType && get_current_user_id() > 0 ? get_current_user_id() : null );
		$actorType    = $explicitType ?? ( null !== $actorUserId ? 'user' : 'system' );
		$actorLabel  = isset( $data['actor_label'] ) ? (string) $data['actor_label'] : $this->userLabel( $actorUserId );

		return new ExamLogInputDTO(
			action:         $action,
			result:         'failure' === ( $data['result'] ?? 'success' ) ? 'failure' : 'success',
			actorType:      $actorType,
			actorUserId:    $actorUserId,
			actorLabel:     $actorLabel,
			eventId:        $eventId,
			sessionId:      $sessionId,
			registrationId: $registrationId,
			attemptId:      $attemptId,
			subjectLabel:   $subject,
			actorIp:        $this->requestContext()->ip,
			context:        is_array( $data['context'] ?? null ) ? $data['context'] : array(),
			createdAt:      $this->clock->now( 'mysql', true ),
		);
	}

	/**
	 * Положительный ID из данных или null.
	 *
	 * @param array<string, mixed> $data Данные действия
	 */
	private function id( array $data, string $key ): ?int {
		$value = (int) ( $data[ $key ] ?? 0 );

		return $value > 0 ? $value : null;
	}

	/** ФИО участника (гостя — из материализатора) или номер, если определить нельзя. */
	private function subject( ?int $participantId ): ?string {
		if ( null === $participantId ) {
			return null;
		}

		$participant = $this->participants->find( $participantId );

		return null !== $participant ? $this->names->participantName( $participant ) : sprintf( 'Участник #%d', $participantId );
	}

	/** Имя сотрудника из профиля WP; без пользователя — null. */
	private function userLabel( ?int $userId ): ?string {
		if ( null === $userId ) {
			return null;
		}

		$user = get_userdata( $userId );

		return $user ? (string) $user->display_name : '#' . $userId;
	}
}
