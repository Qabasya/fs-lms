<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\DTO\Exam\ExamEventDTO;
use Inc\DTO\Exam\ExamSessionDTO;
use Inc\Enums\Exam\ExamEventStatus;
use Inc\Enums\Log\ErrorCode;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Repositories\WPDBRepositories\RoomRepository;
use Inc\Shared\CodedException;

/**
 * Данные экрана «Назначить экзамен»: проведения предмета, выбранное проведение с сеансами, допустимые варианты и кабинеты.
 *
 * Преподаватель видит только свои проведения, пользователь с глобальным доступом — все проведения предмета. Времена в ответе — местные
 * (клиент часовых поясов не считает), даты периода — календарные.
 */
class ExamPlanService {

	/** Название варианта, которого уже нет, — чтобы строка сеанса не оставалась пустой. */
	private const UNKNOWN_VARIANT = 'Вариант удалён';

	public function __construct(
		private readonly ExamEventRepository $events,
		private readonly ExamSessionRepository $sessions,
		private readonly ExamAccessGuard $guard,
		private readonly ExamVariantPolicy $variants,
		private readonly RoomRepository $rooms,
		private readonly AssessmentManager $assessments,
		private readonly ExamTime $time,
	) {}

	/**
	 * @param int|null $eventId Выбранное проведение; null или 0 — самое свежее из доступных.
	 *
	 * @return array{events: list<array<string, mixed>>, event: array<string, mixed>|null, variants: list<array<string, mixed>>, rooms: list<array<string, mixed>>}
	 *
	 * @throws CodedException `ExamAccess`, если предмет или проведение пользователю недоступны.
	 */
	public function build( int $userId, string $subjectKey, ?int $eventId = null ): array {
		if ( ! $this->guard->canManageSubject( $userId, $subjectKey ) ) {
			throw new CodedException( ErrorCode::ExamAccess, 'Нет доступа к экзаменам этого предмета.' );
		}

		$available = $this->availableEvents( $userId, $subjectKey );

		$selected = null;
		if ( null !== $eventId && $eventId > 0 ) {
			$selected = $this->events->find( $eventId );
			if ( null === $selected || $selected->subjectKey !== $subjectKey || ! $this->guard->canManageEvent( $userId, $selected ) ) {
				throw new CodedException( ErrorCode::ExamAccess, 'Нет доступа к этому проведению.' );
			}
		} elseif ( array() !== $available ) {
			$selected = $available[0];
		}

		return array(
			'events'   => array_map( array( $this, 'eventSummary' ), $available ),
			'event'    => null !== $selected ? $this->eventPayload( $selected ) : null,
			'variants' => $this->variants->listForSubject( $subjectKey ),
			'rooms'    => $this->roomsFor( $subjectKey ),
		);
	}

	/** Проведение с сеансами — ответ на правку, публикацию и перезагрузку календаря. @return array<string, mixed> */
	public function eventPayload( ExamEventDTO $event ): array {
		$titles = array();

		return array_merge( $this->eventSummary( $event ), array(
			'subject_key'                => $event->subjectKey,
			'description'                => (string) $event->description,
			'registration_opens_at'      => null !== $event->registrationOpensAt ? $this->time->toLocal( $event->registrationOpensAt ) : '',
			'registration_closes_at'     => null !== $event->registrationClosesAt ? $this->time->toLocal( $event->registrationClosesAt ) : '',
			'guest_registration_enabled' => $event->guestRegistrationEnabled,
			'default_assessment_id'      => (int) $event->defaultAssessmentId,
			'owner_user_id'              => $event->ownerUserId,
			'owner_name'                 => (string) ( get_userdata( $event->ownerUserId )->display_name ?? '' ),
			'sessions'                   => array_map(
				fn ( ExamSessionDTO $session ): array => $this->sessionPayload( $session, $titles ),
				$this->sessions->findByEvent( $event->id )
			),
		) );
	}

	/**
	 * Сеанс для клиента: дата и время — местные, окончание — плановое. `is_locked` — уже была начата попытка.
	 *
	 * @param array<int, string> $variantTitles Кэш названий вариантов в пределах ответа (дополняется).
	 *
	 * @return array<string, mixed>
	 */
	public function sessionPayload( ExamSessionDTO $session, array &$variantTitles = array() ): array {
		$start = $this->time->toLocal( $session->scheduledAt );
		$end   = $this->time->toLocal( $session->plannedEndAt );
		$room  = $this->rooms->find( $session->roomId );

		if ( ! isset( $variantTitles[ $session->assessmentId ] ) ) {
			$variantTitles[ $session->assessmentId ] = $this->assessments->get( $session->assessmentId )->title ?? self::UNKNOWN_VARIANT;
		}

		return array(
			'id'                  => $session->id,
			'event_id'            => $session->eventId,
			'assessment_id'       => $session->assessmentId,
			'assessment_title'    => $variantTitles[ $session->assessmentId ],
			'date'                => substr( $start, 0, 10 ),
			'time_start'          => substr( $start, 11, 5 ),
			'time_end'            => substr( $end, 11, 5 ),
			'room_id'             => $session->roomId,
			'room_name'           => $room->name ?? '',
			'capacity'            => $session->capacity,
			'occupied'            => $session->occupiedCount,
			'status'              => $session->status,
			'is_locked'           => $session->isLocked(),
			'responsible_user_id' => $session->responsibleUserId,
			'version'             => $session->version,
		);
	}

	/**
	 * Кабинеты, в которые можно назначить сеанс предмета: активные, с вместимостью, допускающие предмет.
	 *
	 * @return list<array{id: int, name: string, seats: int}>
	 */
	public function roomsFor( string $subjectKey ): array {
		$result = array();

		foreach ( $this->rooms->findAll( true ) as $room ) {
			if ( $room->hasCapacity() && $room->allowsSubject( $subjectKey ) ) {
				$result[] = array( 'id' => $room->id, 'name' => $room->name, 'seats' => $room->seats );
			}
		}

		return $result;
	}

	/**
	 * @return ExamEventDTO[] Проведения предмета, доступные пользователю: свои — преподавателю, все — глобальному доступу.
	 */
	private function availableEvents( int $userId, string $subjectKey ): array {
		if ( $this->guard->isGlobal( $userId ) ) {
			return $this->events->findBySubjectKey( $subjectKey );
		}

		return array_values( array_filter(
			$this->events->listByOwner( $userId ),
			static fn ( ExamEventDTO $event ): bool => $event->subjectKey === $subjectKey
		) );
	}

	/** @return array<string, mixed> */
	private function eventSummary( ExamEventDTO $event ): array {
		return array(
			'id'           => $event->id,
			'title'        => $event->title,
			'status'       => $event->status,
			'status_label' => ExamEventStatus::tryFrom( $event->status )?->label() ?? $event->status,
			'period_from'  => $event->periodFrom,
			'period_to'    => $event->periodTo,
			'version'      => $event->version,
		);
	}
}
