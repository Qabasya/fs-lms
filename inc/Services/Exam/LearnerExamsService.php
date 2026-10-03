<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Repositories\WPDBRepositories\ExamRegistrationRepository;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Services\Subject\PostTypeResolver;
use Inc\DTO\Exam\ExamEventDTO;

class LearnerExamsService {

	public function __construct(
		private ExamEventRepository $eventRepo,
		private ExamSessionRepository $sessionRepo,
		private ExamParticipationRepository $participationRepo,
		private ExamRegistrationRepository $registrationRepo,
		private AssessmentAttemptRepository $attemptRepo,
		private ExamAudienceResolver $audienceResolver,
		private ExamFormatRegistry $formatRegistry,
	) {}

	/**
	 * Собирает список карточек экзаменов для ученика.
	 *
	 * @param int $personId ID ученика
	 * @param bool $readOnly Режим только чтения (родитель)
	 * @return array Список карточек экзаменов
	 */
	public function build( int $personId, bool $readOnly = false ): array {
		$cards = array();

		// Получить предметы ученика
		$subjectKeys = $this->audienceResolver->subjectKeysForStudent( $personId );

		// TODO: Получить экзамены по этим предметам
		// - проведения в статусах published, completed, cancelled
		// - плюс проведения, где у ученика есть participation

		// Пока вернём пустой список
		return array( 'exams' => $cards );
	}

	/**
	 * Резолвит состояние карточки по условиям (5.2.2)
	 */
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
					'actions' => array( 'start' ), // этап 6: будет активна
				);
			}

			// Сеанс ещё не начался
			if ( $scheduledAt && $now < $scheduledAt ) {
				return array(
					'state'   => 'registered',
					'actions' => array( 'change', 'cancel' ),
				);
			}

			// Сеанс закончился
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
				$scheduledAt = $session['scheduled_at'] ?? null;
				if ( $scheduledAt && current_time( 'mysql', true ) < $scheduledAt ) {
					return true;
				}
			}
		}
		return false;
	}

	private function canRegisterAgain( ExamEventDTO $event, array $sessions ): bool {
		// Можно записаться если запись открыта и есть места
		$now = current_time( 'mysql', true );
		if ( $now < $event->registration_opens_at || $now >= $event->registration_closes_at ) {
			return false;
		}
		return $this->hasFreeSlot( $sessions );
	}

	/**
	 * Собирает список грядущих событий для расписания (5.5.1)
	 */
	public function upcomingEvents( int $personId ): array {
		$events = array();

		// TODO: Получить действующие записи на будущие сеансы
		// Формат: array{ kind:'exam', event_id, title, date, start, end, room, state }

		return $events;
	}
}
