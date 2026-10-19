<?php
/**
 * Карточки «Моих экзаменов» ученика и родителя (этап 5).
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\Managers\Person\UserManager;

use Inc\DTO\Assessment\AttemptDTO;
use Inc\DTO\Exam\ExamEventDTO;
use Inc\DTO\Exam\ExamParticipationDTO;
use Inc\DTO\Exam\ExamRegistrationDTO;
use Inc\DTO\Exam\ExamSessionDTO;
use Inc\Enums\Assessment\AttemptStatus;
use Inc\Enums\Exam\ExamEventStatus;
use Inc\Enums\Exam\ExamRegistrationStatus;
use Inc\Enums\Exam\ExamSessionStatus;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Repositories\WPDBRepositories\ExamRegistrationRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Repositories\WPDBRepositories\RoomRepository;
use Inc\Shared\PluginLogger;

/**
 * Карточки «Моих экзаменов» ученика и родителя (этап 5).
 *
 * Сервер отдаёт готовое состояние и список разрешённых действий; клиент ничего не вычисляет по датам.
 * Баллы, эталоны и решения здесь не отдаются вообще: результат раскрывает только ExamReviewProjection.
 * Адрес станции (`station_url`) — только ученику и только в состояниях «Приступить»/«Продолжить»: родитель сдавать не может,
 * из действий ему остаётся «Результаты».
 * Все времена в ответе — местные; в таблицах `exam_*` они хранятся в UTC.
 */
class LearnerExamsService {

	/** Собирает карточки из аудитории, записей и попыток; доступность разбора проверяет проекция. */
	public function __construct(
		private readonly ExamAudienceResolver $audience,
		private readonly ExamEventRepository $events,
		private readonly ExamSessionRepository $sessions,
		private readonly ExamParticipationRepository $participations,
		private readonly ExamRegistrationRepository $registrations,
		private readonly AssessmentAttemptRepository $attempts,
		private readonly AssessmentManager $assessments,
		private readonly RoomRepository $rooms,
		private readonly ExamFormatRegistry $formats,
		private readonly ExamNoShowService $noShow,
		private readonly ExamTime $time,
		private readonly ExamReviewProjection $reviews,
		private readonly UserManager $users,
	) {}

	/**
	 * Собирает доступные человеку экзамены и состояние его записей для кабинета.
	 *
	 * @return array{exams: list<array<string, mixed>>}
	 */
	public function build( int $personId, bool $viewOnly = false ): array {
		$nowUtc = $this->time->nowUtc();
		$cards  = array();

		foreach ( $this->visibleEvents( $personId ) as $event ) {
			$cards[] = $this->card( $event, $personId, $viewOnly, $nowUtc );
		}

		return array( 'exams' => $cards );
	}

	/**
	 * Действующие записи ученика на идущие и будущие сеансы — для расписания.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function upcomingEvents( int $personId ): array {
		$nowUtc = $this->time->nowUtc();
		$result = array();

		foreach ( $this->events->findByIds( $this->participations->findEventIdsForPerson( $personId ) ) as $event ) {
			if ( ExamEventStatus::Published->value !== $event->status ) {
				continue;
			}

			$participation = $this->participations->findByEventAndPerson( $event->id, $personId );
			$registration  = null !== $participation ? $this->registrations->findActive( $participation->id ) : null;
			$session       = null !== $registration ? $this->sessions->find( $registration->sessionId ) : null;
			if ( null === $participation || null === $registration || null === $session || $nowUtc >= $session->plannedEndAt ) {
				continue;
			}

			$attempt = $this->currentAttempt( $participation );
			$start   = $this->local( $session->scheduledAt );
			$room    = $this->roomName( $session->roomId );

			$result[] = array(
				'kind'       => 'exam',
				'event_id'   => $event->id,
				'title'      => $event->title,
				'date'       => $start['date'],
				'start'      => $start['time'],
				'end'        => $this->local( $session->plannedEndAt )['time'],
				'room'       => $room,
				'room_name'  => $room,
				'state'      => $this->resolveState( $event, $participation, $registration, null, $attempt, $session, $nowUtc, false )['state'],
				'deadline'   => null !== $attempt && AttemptStatus::InProgress === $attempt->status ? substr( $attempt->deadlineAt, 11, 5 ) : null,
				'group_name' => 'Экзамен',
				'topic'      => $event->title,
			);
		}

		usort(
			$result,
			static fn ( array $a, array $b ): int => strcmp( $a['date'] . ' ' . $a['start'], $b['date'] . ' ' . $b['start'] )
		);

		return $result;
	}

	/**
	 * Проведения предметов ученика (опубликованные, завершённые, отменённые) плюс те, где у него уже есть участие:
	 * историческая запись не исчезает при смене группы. Черновики не показываются никогда.
	 *
	 * @return ExamEventDTO[]
	 */
	private function visibleEvents( int $personId ): array {
		$statuses = array(
			ExamEventStatus::Published->value,
			ExamEventStatus::Completed->value,
			ExamEventStatus::Cancelled->value,
		);

		$bySubject  = $this->events->findBySubjectsAndStatuses( $this->audience->subjectKeysForStudent( $personId ), $statuses );
		$historical = $this->events->findByIds( $this->participations->findEventIdsForPerson( $personId ) );

		$unique = array();
		foreach ( array_merge( $bySubject, $historical ) as $event ) {
			if ( ExamEventStatus::Draft->value === $event->status ) {
				continue;
			}
			$unique[ $event->id ] = $event;
		}

		return array_values( $unique );
	}

	/**
	 * Готовит карточку проведения с текущим участием и доступными действиями.
	 *
	 * @return array<string, mixed>
	 */
	private function card( ExamEventDTO $event, int $personId, bool $viewOnly, string $nowUtc ): array {
		$participation = $this->participations->findByEventAndPerson( $event->id, $personId );
		$registration  = null !== $participation ? $this->registrations->findActive( $participation->id ) : null;

		// Ленивая неявка: ученик видит «пропущено» сразу, не дожидаясь cron.
		if ( null !== $participation && null !== $registration ) {
			$registration  = $this->settleMissed( $participation, $registration, $nowUtc );
			$participation = $this->participations->find( $participation->id ) ?? $participation;
		}

		$sessions   = $this->sessions->findByEvent( $event->id );
		$session    = null !== $registration ? $this->sessionById( $sessions, $registration->sessionId ) : null;
		$attempt    = null !== $participation ? $this->currentAttempt( $participation ) : null;
		$last       = null !== $participation && null === $registration ? $this->lastClosedRegistration( $participation ) : null;
		$futureList = $this->futureSessions( $sessions, $registration, $nowUtc );
		$stateInfo  = $this->resolveState( $event, $participation, $registration, $last, $attempt, $session, $nowUtc, array() !== $this->freeSessions( $futureList ), $futureList );

		$lastSession = null !== $last ? $this->sessionById( $sessions, $last->sessionId ) : null;

		$card = array(
			'event_id'               => $event->id,
			'title'                  => $event->title,
			'description'            => $event->description,
			'subject_key'            => $event->subjectKey,
			'direction'              => $this->format( $event, $sessions )?->direction->value ?? '',
			'state'                  => $stateInfo['state'],
			'state_label'            => $this->stateLabel( $stateInfo['state'] ),
			'actions'                => $viewOnly ? $this->readOnlyActions( $stateInfo['actions'] ) : $stateInfo['actions'],
			'period_from'            => $event->periodFrom,
			'period_to'              => $event->periodTo,
			'registration_opens_at'  => null !== $event->registrationOpensAt ? $this->time->toLocal( $event->registrationOpensAt ) : null,
			'registration_closes_at' => null !== $event->registrationClosesAt ? $this->time->toLocal( $event->registrationClosesAt ) : null,
			'registration'           => null !== $registration && null !== $session ? $this->registrationBlock( $registration, $session, $participation ) : null,
			'last_reason'            => $this->lastReason( $event, $last ),
			'previous_date'          => null !== $lastSession && ExamRegistrationStatus::Missed->value === $last->status ? $this->local( $lastSession->scheduledAt )['date'] : null,
			'sessions'               => $futureList,
			'teacher_name'           => $this->ownerName( $event->ownerUserId ),
			'format'                 => $this->formatBlock( $event, $sessions ),
		);

		if ( ! $viewOnly ) {
			$card += $this->stationBlock( $stateInfo['state'], $registration, $session, $attempt );
		}

		return 'approved' === $stateInfo['state'] && null !== $participation
			? $card + $this->approvedResult( $personId, $event->id )
			: $card;
	}

	/**
	 * Родитель только читает: запись, перенос, отмена и сдача — за учеником; результат ребёнка он смотрит.
	 *
	 * @param string[] $actions
	 *
	 * @return string[]
	 */
	private function readOnlyActions( array $actions ): array {
		return array_values( array_intersect( $actions, array( 'results' ) ) );
	}

	/**
	 * Запуск и продолжение попытки: адрес станции по записи, а для идущей попытки — личный дедлайн.
	 * В остальных состояниях ключей нет. Время — местное.
	 *
	 * @return array<string, mixed>
	 */
	private function stationBlock( string $state, ?ExamRegistrationDTO $registration, ?ExamSessionDTO $session, ?AttemptDTO $attempt ): array {
		if ( 'in_progress' === $state && null !== $attempt && null !== $attempt->examRegistrationId ) {
			return array(
				'station_url'  => $this->assessments->examStationUrl( $attempt->assessmentId, $attempt->examRegistrationId ),
				'deadline'     => substr( $attempt->deadlineAt, 11, 5 ),
				'seconds_left' => $this->time->secondsUntil( $this->time->nowLocal(), $attempt->deadlineAt ),
			);
		}

		if ( 'entry_open' === $state && null !== $registration && null !== $session ) {
			return array( 'station_url' => $this->assessments->examStationUrl( $session->assessmentId, $registration->id ) );
		}

		return array();
	}

	/**
	 * Итог и перечень единиц — только для раскрытого результата; в остальных состояниях ключей нет.
	 *
	 * @return array<string, mixed>
	 */
	private function approvedResult( int $personId, int $eventId ): array {
		$review = $this->reviews->forStudent( $personId, $eventId );
		if ( null === $review || true !== ( $review['revealed'] ?? false ) ) {
			return array();
		}

		return array(
			'result'      => $review['result'],
			'approved_at' => $review['approved_at'] ?? null,
			'units'       => array_map(
				static fn ( array $unit ): array => array(
					'number' => $unit['number'],
					'status' => $unit['status'],
					'anchor' => $unit['anchor'],
				),
				$review['units']
			),
		);
	}

	/**
	 * Состояние карточки и разрешённые действия — по таблице спецификации 5.2.2.
	 *
	 * @param list<array<string, mixed>> $futureList
	 *
	 * @return array{state: string, actions: string[]}
	 */
	private function resolveState(
		ExamEventDTO $event,
		?ExamParticipationDTO $participation,
		?ExamRegistrationDTO $registration,
		?ExamRegistrationDTO $last,
		?AttemptDTO $attempt,
		?ExamSessionDTO $session,
		string $nowUtc,
		bool $hasFreeSeat,
		array $futureList = array(),
	): array {
		if ( ExamEventStatus::Cancelled->value === $event->status ) {
			return array(
				'state'   => 'event_cancelled',
				'actions' => array(),
			);
		}

		if ( null !== $attempt ) {
			if ( AttemptStatus::InProgress === $attempt->status ) {
				return array(
					'state'   => 'in_progress',
					'actions' => array( 'resume' ),
				);
			}
			return $attempt->isApproved()
				? array(
					'state'   => 'approved',
					'actions' => array( 'results' ),
				)
				: array(
					'state'   => 'awaiting_approval',
					'actions' => array(),
				);
		}

		$windowOpen = $this->registrationWindowOpen( $event, $nowUtc );

		if ( null !== $registration && null !== $session ) {
			if ( $nowUtc < $session->scheduledAt ) {
				return array(
					'state'   => 'registered',
					'actions' => $windowOpen ? array( 'change', 'cancel' ) : array(),
				);
			}
			if ( $nowUtc < $session->plannedEndAt ) {
				return array(
					'state'   => 'entry_open',
					'actions' => array( 'start' ),
				);
			}
		}

		$canRegister = $windowOpen && $hasFreeSeat;

		if ( null !== $last ) {
			if ( ExamRegistrationStatus::Missed->value === $last->status ) {
				return array(
					'state'   => 'missed',
					'actions' => $canRegister ? array( 'register' ) : array(),
				);
			}
			if ( ExamRegistrationStatus::Cancelled->value === $last->status && null !== $last->actorUserId ) {
				return array(
					'state'   => 'cancelled_by_staff',
					'actions' => $canRegister ? array( 'register' ) : array(),
				);
			}
		}

		if ( null !== $event->registrationOpensAt && $nowUtc < $event->registrationOpensAt ) {
			return array(
				'state'   => 'not_open',
				'actions' => array(),
			);
		}
		if ( ( null !== $event->registrationClosesAt && $nowUtc >= $event->registrationClosesAt ) || array() === $futureList ) {
			return array(
				'state'   => 'closed',
				'actions' => array(),
			);
		}

		return $hasFreeSeat
			? array(
				'state'   => 'open',
				'actions' => array( 'register' ),
			)
			: array(
				'state'   => 'full',
				'actions' => array(),
			);
	}

	/**
	 * Проверяет UTC-окно записи: начало включительно, конец исключительно.
	 */
	private function registrationWindowOpen( ExamEventDTO $event, string $nowUtc ): bool {
		return ( null === $event->registrationOpensAt || $nowUtc >= $event->registrationOpensAt )
			&& ( null === $event->registrationClosesAt || $nowUtc < $event->registrationClosesAt );
	}

	/** Сеанс закончился, попытки нет — проставить неявку и перечитать запись. */
	private function settleMissed( ExamParticipationDTO $participation, ExamRegistrationDTO $registration, string $nowUtc ): ?ExamRegistrationDTO {
		$session = $this->sessions->find( $registration->sessionId );
		if ( null === $session || null !== $participation->currentAttemptId || $nowUtc < $session->plannedEndAt ) {
			return $registration;
		}

		try {
			$this->noShow->markMissed( $registration->id );
		} catch ( \Throwable $e ) {
			PluginLogger::exception( 'LearnerExamsService', $e, array( 'registration_id' => $registration->id ), true );
			return $registration;
		}

		return $this->registrations->findActive( $participation->id );
	}

	/**
	 * Находит попытку по текущему указателю участия; без указателя возвращает null.
	 */
	private function currentAttempt( ExamParticipationDTO $participation ): ?AttemptDTO {
		return null !== $participation->currentAttemptId ? $this->attempts->find( $participation->currentAttemptId ) : null;
	}

	/** Последняя закрытая запись участия (для состояний «пропущено» и «отменена сотрудником»). */
	private function lastClosedRegistration( ExamParticipationDTO $participation ): ?ExamRegistrationDTO {
		$all = $this->registrations->findByParticipation( $participation->id );
		return array() !== $all ? $all[ array_key_last( $all ) ] : null;
	}

	/**
	 * Будущие открытые сеансы для карусели.
	 *
	 * @param ExamSessionDTO[] $sessions
	 *
	 * @return list<array<string, mixed>>
	 */
	private function futureSessions( array $sessions, ?ExamRegistrationDTO $registration, string $nowUtc ): array {
		$result = array();
		foreach ( $sessions as $session ) {
			if ( ExamSessionStatus::Open->value !== $session->status || $nowUtc >= $session->scheduledAt ) {
				continue;
			}
			// Сеанс только для гостей ученик не видит; свой текущий сеанс остаётся в списке.
			if ( ! $session->visibleToStudents && ( null === $registration || $registration->sessionId !== $session->id ) ) {
				continue;
			}

			$free  = max( 0, $session->capacity - $session->occupiedCount );
			$start = $this->local( $session->scheduledAt );

			$result[] = array(
				'session_id' => $session->id,
				'date'       => $start['date'],
				'weekday'    => $start['weekday'],
				'time_start' => $start['time'],
				'time_end'   => $this->local( $session->plannedEndAt )['time'],
				'room'       => $this->roomName( $session->roomId ),
				'free'       => $free,
				'capacity'   => $session->capacity,
				'selectable' => $free > 0,
				'is_current' => null !== $registration && $registration->sessionId === $session->id,
			);
		}
		return $result;
	}

	/**
	 * Оставляет будущие сеансы со свободными местами, исключая текущую запись.
	 *
	 * @param list<array<string, mixed>> $futureList
	 *
	 * @return list<array<string, mixed>>
	 */
	private function freeSessions( array $futureList ): array {
		return array_values( array_filter( $futureList, static fn ( array $s ): bool => $s['free'] > 0 && empty( $s['is_current'] ) ) );
	}

	/**
	 * Готовит сведения о текущей записи и локальном времени сеанса.
	 *
	 * @return array<string, mixed>
	 */
	private function registrationBlock( ExamRegistrationDTO $registration, ExamSessionDTO $session, ExamParticipationDTO $participation ): array {
		$start = $this->local( $session->scheduledAt );

		return array(
			'registration_id' => $registration->id,
			'version'         => $participation->version, // клиент возвращает её при переносе и отмене (`ExamStale`)
			'session_id'      => $session->id,
			'date'            => $start['date'],
			'weekday'         => $start['weekday'],
			'time_start'      => $start['time'],
			'time_end'        => $this->local( $session->plannedEndAt )['time'],
			// Секунды до начала и конца окна на момент ответа: клиент отсчитывает их сам (фаза «скоро» — за 5 минут).
			'starts_in'       => $this->time->secondsUntil( $this->time->nowUtc(), $session->scheduledAt ),
			'ends_in'         => $this->time->secondsUntil( $this->time->nowUtc(), $session->plannedEndAt ),
			'room'            => $this->roomName( $session->roomId ),
		);
	}

	/**
	 * Выбирает причину отмены проведения или последней записи для карточки.
	 */
	private function lastReason( ExamEventDTO $event, ?ExamRegistrationDTO $last ): ?string {
		if ( ExamEventStatus::Cancelled->value === $event->status ) {
			return $event->cancelReason;
		}
		return null !== $last && null !== $last->actorUserId ? $last->reason : null;
	}

	/**
	 * Находит сеанс в уже загруженном списке; отсутствие возвращает как null.
	 *
	 * @param ExamSessionDTO[] $sessions
	 */
	private function sessionById( array $sessions, int $id ): ?ExamSessionDTO {
		foreach ( $sessions as $session ) {
			if ( $session->id === $id ) {
				return $session;
			}
		}
		return $this->sessions->find( $id );
	}

	/**
	 * Формат проведения — по основному варианту (или по варианту первого сеанса).
	 *
	 * @param ExamSessionDTO[] $sessions
	 */
	private function format( ExamEventDTO $event, array $sessions ): ?\Inc\DTO\Exam\ExamFormatDTO {
		$assessmentId = $event->defaultAssessmentId ?? ( $sessions[0]->assessmentId ?? null );
		$assessment   = null !== $assessmentId ? $this->assessments->get( $assessmentId ) : null;

		return null !== $assessment ? $this->formats->for( $assessment->kind ) : null;
	}

	/**
	 * Готовит формат работы и длительность сеансов, если формат определён.
	 *
	 * @param ExamSessionDTO[] $sessions
	 *
	 * @return array<string, mixed>|null
	 */
	private function formatBlock( ExamEventDTO $event, array $sessions ): ?array {
		$format = $this->format( $event, $sessions );
		if ( null === $format ) {
			return null;
		}

		return array(
			'direction'        => $format->direction->value,
			'unit_count'       => $format->unitCount,
			'primary_max'      => $format->primaryMax,
			'secondary_max'    => $format->secondaryMax,
			'grade_max'        => $format->gradeMax,
			'duration_minutes' => $format->durationMinutes,
		);
	}

	/**
	 * Преобразует вычисленное состояние карточки в пользовательскую подпись.
	 */
	private function stateLabel( string $state ): string {
		return match ( $state ) {
			'event_cancelled'    => 'Проведение отменено',
			'not_open'           => 'Запись не открыта',
			'open'               => 'Запись открыта',
			'full'               => 'Свободных мест нет',
			'closed'             => 'Запись закрыта',
			'registered'         => 'Запись подтверждена',
			'entry_open'         => 'Экзамен идёт',
			'in_progress'        => 'Выполняется',
			'awaiting_approval'  => 'Ожидает утверждения',
			'approved'           => 'Завершён',
			'cancelled_by_staff' => 'Запись отменена',
			'missed'             => 'Экзамен пропущен',
			default              => $state,
		};
	}

	/**
	 * Возвращает название назначенного кабинета или null при его отсутствии.
	 */
	private function roomName( int $roomId ): ?string {
		return $roomId > 0 ? $this->rooms->find( $roomId )?->name : null;
	}

	/**
	 * Возвращает отображаемое имя ответственного; удалённый пользователь даёт null.
	 */
	private function ownerName( int $userId ): ?string {
		$user = $userId > 0 ? $this->users->find( $userId ) : null;
		return null !== $user ? (string) $user->display_name : null;
	}

	/**
	 * UTC → местные дата, время и день недели.
	 *
	 * @return array{date: string, time: string, weekday: string}
	 */
	private function local( string $utc ): array {
		$local = $this->time->toLocal( $utc );

		return array(
			'date'    => substr( $local, 0, 10 ),
			'time'    => substr( $local, 11, 5 ),
			'weekday' => wp_date( 'l', ( new \DateTimeImmutable( $utc, new \DateTimeZone( 'UTC' ) ) )->getTimestamp() ),
		);
	}
}
