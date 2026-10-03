<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Repositories\WPDBRepositories\ExamRegistrationRepository;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\RoomRepository;
use Inc\DTO\Exam\ExamEventDTO;

class LearnerExamsService {

	public function __construct(
		private ExamEventRepository $eventRepo,
		private ExamSessionRepository $sessionRepo,
		private ExamParticipationRepository $participationRepo,
		private ExamRegistrationRepository $registrationRepo,
		private AssessmentAttemptRepository $attemptRepo,
		private PersonRepository $personRepo,
		private RoomRepository $roomRepo,
		private ExamAudienceResolver $audienceResolver,
		private ExamFormatRegistry $formatRegistry,
	) {}

	public function build( int $personId, bool $readOnly = false ): array {
		$cards = array();

		// Получить предметы ученика
		$subjectKeys = $this->audienceResolver->subjectKeysForStudent( $personId );
		if ( empty( $subjectKeys ) ) {
			return array( 'exams' => $cards );
		}

		// Получить разрешённые направления (ОГЭ/ЕГЭ)
		$allowedDirections = $this->audienceResolver->allowedDirectionsForStudent( $personId );

		// Получить экзамены: опубликованные + завершённые + отменённые
		$events = $this->eventRepo->findBySubjectsAndStatuses(
			$subjectKeys,
			array( 'published', 'completed', 'cancelled' )
		);

		// Фильтровать события по разрешённым направлениям
		if ( ! empty( $allowedDirections ) ) {
			$events = array_filter( $events, function( $event ) use ( $allowedDirections ) {
				return in_array( $event->direction, $allowedDirections, true );
			} );
		}

		// Плюс экзамены, где у ученика есть participation (историческая запись)
		$eventIdsWithParticipation = $this->participationRepo->findEventIdsForStudent( $personId );
		if ( ! empty( $eventIdsWithParticipation ) ) {
			$historicalEvents = $this->eventRepo->findByIds( $eventIdsWithParticipation );
			$events = array_merge( $events, $historicalEvents );
			// Удалить дубликаты
			$seenIds = array();
			$unique = array();
			foreach ( $events as $event ) {
				if ( ! in_array( $event->id, $seenIds, true ) ) {
					$unique[] = $event;
					$seenIds[] = $event->id;
				}
			}
			$events = $unique;
		}

		// Собрать карточку для каждого события
		foreach ( $events as $event ) {
			$eventId = $event->id;

			// Получить данные студента для этого события
			$registration = $this->registrationRepo->findActiveByEventAndStudent( $eventId, $personId );
			$participation = $this->participationRepo->findByEventAndStudent( $eventId, $personId );
			$attempt = null;
			if ( $participation ) {
				$attempt = $this->attemptRepo->findLatestByParticipation( $participation->id );
			}

			// Получить сеансы события
			$sessions = $this->buildSessionsList( $eventId, $registration );

			// Определить состояние и действия
			$stateInfo = $this->resolveState(
				$event,
				$registration,
				$attempt ? $attempt->toArray() : null,
				$sessions
			);

			// Собрать карточку
			$card = array(
				'event_id'                   => $eventId,
				'title'                      => $event->title,
				'description'                => $event->description,
				'subject_key'                => $event->subject_key,
				'direction'                  => $event->direction,
				'state'                      => $stateInfo['state'],
				'state_label'                => $this->getStateLabel( $stateInfo['state'] ),
				'actions'                    => $readOnly ? array() : $stateInfo['actions'],
				'period_from'                => $event->registration_opens_at,
				'period_to'                  => $event->registration_closes_at,
				'registration_opens_at'      => $event->registration_opens_at,
				'registration_closes_at'     => $event->registration_closes_at,
				'registration'               => $registration ? array(
					'registration_id' => $registration->id,
					'session_id'      => $registration->session_id,
					'date'            => $registration->scheduled_at ? wp_date( 'Y-m-d', strtotime( $registration->scheduled_at ) ) : null,
					'weekday'         => $registration->scheduled_at ? wp_date( 'l', strtotime( $registration->scheduled_at ) ) : null,
					'time_start'      => $registration->scheduled_at ? wp_date( 'H:i', strtotime( $registration->scheduled_at ) ) : null,
					'time_end'        => $registration->planned_end_at ? wp_date( 'H:i', strtotime( $registration->planned_end_at ) ) : null,
					'room'            => $this->getRoomName( $registration->room_id ?? null ),
				) : null,
				'last_reason'                => $participation?->cancellation_reason,
				'previous_date'              => null,
				'sessions'                   => $sessions,
				'teacher_name'               => $this->getTeacherName( $event->teacher_person_id ?? null ),
				'format'                     => $this->formatRegistry->for( $event->subject_key, $event->direction )?->toArray(),
			);

			$cards[] = $card;
		}

		return array( 'exams' => $cards );
	}

	private function buildSessionsList( int $eventId, ?array $registration ): array {
		$now = current_time( 'mysql', true );
		$sessions = array();

		$dbSessions = $this->sessionRepo->findByEvent( $eventId );
		foreach ( $dbSessions as $session ) {
			if ( 'cancelled' === $session->status ) {
				continue;
			}

			$scheduledAt = $session->scheduled_at;
			if ( ! $scheduledAt || $now > $scheduledAt ) {
				continue;
			}

			$free = max( 0, $session->capacity - $session->occupied_count );

			$sessions[] = array(
				'session_id'  => $session->id,
				'date'        => wp_date( 'Y-m-d', strtotime( $scheduledAt ) ),
				'weekday'     => wp_date( 'l', strtotime( $scheduledAt ) ),
				'time_start'  => wp_date( 'H:i', strtotime( $scheduledAt ) ),
				'time_end'    => wp_date( 'H:i', strtotime( $session->planned_end_at ) ),
				'room'        => $this->getRoomName( $session->room_id ?? null ),
				'free'        => $free,
				'capacity'    => $session->capacity,
				'selectable'  => $free > 0,
				'is_current'  => $registration && $registration['session_id'] === $session->id,
			);
		}

		return $sessions;
	}

	private function resolveState(
		ExamEventDTO $event,
		?array $registration,
		?array $attempt,
		array $sessions
	): array {
		$now = current_time( 'mysql', true );

		// event_cancelled
		if ( 'cancelled' === $event->status ) {
			return array(
				'state'   => 'event_cancelled',
				'actions' => array(),
			);
		}

		// Проверить есть ли активная запись
		$hasActiveRegistration = null !== $registration;
		$hasAttempt = null !== $attempt;

		// Есть попытка в процессе
		if ( $hasAttempt && 'in_progress' === $attempt['status'] ) {
			return array(
				'state'   => 'in_progress',
				'actions' => array( 'resume' ),
			);
		}

		// Есть попытка, ожидает утверждения
		if ( $hasAttempt && 'pending' === $attempt['status'] ) {
			return array(
				'state'   => 'awaiting_approval',
				'actions' => array(),
			);
		}

		// Попытка утверждена
		if ( $hasAttempt && 'approved' === $attempt['status'] ) {
			return array(
				'state'   => 'approved',
				'actions' => array( 'results' ),
			);
		}

		// Есть запись
		if ( $hasActiveRegistration ) {
			$scheduledAt = $registration['scheduled_at'] ?? null;
			$plannedEnd = $registration['planned_end_at'] ?? null;

			// Сеанс начался
			if ( $scheduledAt && $now >= $scheduledAt && $plannedEnd && $now < $plannedEnd ) {
				return array(
					'state'   => 'entry_open',
					'actions' => array( 'start' ),
				);
			}

			// Сеанс ещё не начался
			if ( $scheduledAt && $now < $scheduledAt ) {
				return array(
					'state'   => 'registered',
					'actions' => array( 'change', 'cancel' ),
				);
			}

			// Сеанс закончился - пропущен или отменён
			return array(
				'state'   => 'missed',
				'actions' => $this->canRegisterAgain( $event, $sessions ) ? array( 'register' ) : array(),
			);
		}

		// Нет записи - проверить может ли записаться

		// Запись закрыта
		if ( $now >= $event->registration_closes_at ) {
			return array(
				'state'   => 'closed',
				'actions' => array(),
			);
		}

		// Запись ещё не открыта
		if ( $now < $event->registration_opens_at ) {
			return array(
				'state'   => 'not_open',
				'actions' => array(),
			);
		}

		// Есть свободное место
		if ( $this->hasFreeSlot( $sessions ) ) {
			return array(
				'state'   => 'open',
				'actions' => array( 'register' ),
			);
		}

		// Мест нет
		return array(
			'state'   => 'full',
			'actions' => array(),
		);
	}

	private function hasFreeSlot( array $sessions ): bool {
		foreach ( $sessions as $session ) {
			if ( isset( $session['free'] ) && $session['free'] > 0 ) {
				if ( isset( $session['time_start'] ) ) {
					return true;
				}
			}
		}
		return false;
	}

	private function canRegisterAgain( ExamEventDTO $event, array $sessions ): bool {
		$now = current_time( 'mysql', true );
		if ( $now < $event->registration_opens_at || $now >= $event->registration_closes_at ) {
			return false;
		}
		return $this->hasFreeSlot( $sessions );
	}

	private function getStateLabel( string $state ): string {
		$labels = array(
			'event_cancelled'   => 'Проведение отменено',
			'not_open'          => 'Запись не открыта',
			'open'              => 'Запись открыта',
			'full'              => 'Свободных мест нет',
			'closed'            => 'Запись закрыта',
			'registered'        => 'Зарегистрирован',
			'entry_open'        => 'Экзамен идёт',
			'in_progress'       => 'Выполняется',
			'awaiting_approval' => 'Ожидает утверждения',
			'approved'          => 'Завершён',
			'cancelled_by_staff' => 'Запись отменена',
			'missed'            => 'Пропущен',
		);
		return $labels[ $state ] ?? $state;
	}

	private function getRoomName( ?int $roomId ): ?string {
		if ( ! $roomId ) {
			return null;
		}
		try {
			$room = $this->roomRepo->findById( $roomId );
			return $room?->name;
		} catch ( \Exception $e ) {
			return null;
		}
	}

	private function getTeacherName( ?int $personId ): ?string {
		if ( ! $personId ) {
			return null;
		}
		try {
			$person = $this->personRepo->findById( $personId );
			return $person ? trim( $person->last_name . ' ' . $person->first_name ) : null;
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/**
	 * Форматирует результат для отображения в зависимости от направления.
	 * ОГЭ — оценка (2-5), ЕГЭ — баллы (0-100).
	 *
	 * @param ?array $attempt Данные попытки (может быть null)
	 * @param string $direction Направление (oge, ege, и т.д.)
	 *
	 * @return ?string Форматированный результат или null
	 */
	public function resultCaption( ?array $attempt, string $direction ): ?string {
		if ( ! $attempt || ! isset( $attempt['score'] ) ) {
			return null;
		}

		$score = (int) $attempt['score'];

		// ОГЭ: оценка (2-5)
		if ( 'oge' === $direction ) {
			$gradeMap = array( 2, 3, 4, 5 );
			$grade = $gradeMap[ min( $score, 3 ) ] ?? 2;
			return (string) $grade;
		}

		// ЕГЭ: баллы (0-100)
		if ( 'ege' === $direction ) {
			return (string) $score;
		}

		// По умолчанию просто баллы
		return (string) $score;
	}

	public function upcomingEvents( int $personId ): array {
		$now = current_time( 'mysql', true );
		$events = array();

		// Получить участия и их активные регистрации
		$eventIdsWithParticipation = $this->participationRepo->findEventIdsForStudent( $personId );
		if ( empty( $eventIdsWithParticipation ) ) {
			return $events;
		}

		$events_db = $this->eventRepo->findByIds( $eventIdsWithParticipation );
		foreach ( $events_db as $event ) {
			// Получить активную регистрацию
			$participation = $this->participationRepo->findByEventAndStudent( $event->id, $personId );
			if ( ! $participation ) {
				continue;
			}

			$registration = $this->registrationRepo->findActive( $participation->id );
			if ( ! $registration ) {
				continue;
			}

			// Получить сеанс
			$session = $this->sessionRepo->find( $registration->session_id );
			if ( ! $session || $session->scheduled_at <= $now ) {
				continue; // Прошлый сеанс
			}

			// Получить попытку для определения состояния
			$attempt = $this->attemptRepo->findLatestByParticipation( $participation->id );

			// Определить состояние
			$state = 'registered';
			if ( $attempt ) {
				$attemptArr = $attempt->toArray();
				if ( 'in_progress' === $attemptArr['status'] ) {
					$state = 'in_progress';
				} elseif ( 'approved' === $attemptArr['status'] ) {
					$state = 'approved';
				}
			}

			$events[] = array(
				'kind'        => 'exam',
				'event_id'    => $event->id,
				'title'       => $event->title,
				'date'        => wp_date( 'Y-m-d', strtotime( $session->scheduled_at ) ),
				'start'       => wp_date( 'H:i', strtotime( $session->scheduled_at ) ),
				'end'         => wp_date( 'H:i', strtotime( $session->planned_end_at ) ),
				'room'        => $this->getRoomName( $session->room_id ?? null ),
				'state'       => $state,
				'deadline'    => $attempt ? wp_date( 'H:i', strtotime( $attempt->deadline_at ?? $session->planned_end_at ) ) : null,
				'group_name'  => 'Экзамен',
				'topic'       => $event->title,
				'room_name'   => $this->getRoomName( $session->room_id ?? null ),
			);
		}

		// Отсортировать по дате и времени
		usort( $events, function( $a, $b ) {
			$aTime = strtotime( $a['date'] . ' ' . $a['start'] );
			$bTime = strtotime( $b['date'] . ' ' . $b['start'] );
			return $aTime - $bTime;
		} );

		return $events;
	}
}
