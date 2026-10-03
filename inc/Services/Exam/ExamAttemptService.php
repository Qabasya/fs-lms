<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\DTO\Assessment\AttemptDTO;
use Inc\DTO\Exam\AttemptContext;
use Inc\Enums\Auth\ErrorCode;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Repositories\WPDBRepositories\ExamRegistrationRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Services\Assessment\AttemptService;
use Inc\Services\Assessment\AutoGradeService;
use Inc\Shared\CodedException;
use Inc\Shared\TransactionRunner;

/**
 * Управление попытками экзамена: старт, продолжение, сохранение, сдача (6.1–6.2).
 */
class ExamAttemptService {

	public function __construct(
		private readonly TransactionRunner $transactionRunner,
		private readonly ExamParticipationRepository $participationRepo,
		private readonly ExamRegistrationRepository $registrationRepo,
		private readonly ExamSessionRepository $sessionRepo,
		private readonly ExamEventRepository $eventRepo,
		private readonly AssessmentAttemptRepository $attemptRepo,
		private readonly PersonRepository $personRepo,
		private readonly AttemptService $attemptService,
		private readonly AutoGradeService $autoGradeService,
		private readonly ExamNoShowService $noShowService,
		private readonly ExamFormatRegistry $formatRegistry,
		private readonly ExamOutbox $outbox,
		private readonly ExamTime $time,
	) {}

	/**
	 * Контекст участника для старта попытки (6.1.4).
	 *
	 * @param int $wpUserId WP-пользователь
	 * @param int $registrationId ID записи (из URL)
	 *
	 * @return AttemptContext
	 * @throws CodedException
	 */
	public function contextForStudent( int $wpUserId, int $registrationId ): AttemptContext {
		$registration = $this->registrationRepo->find( $registrationId );
		if ( ! $registration ) {
			throw new CodedException( ErrorCode::ExamAccess, 'Экзамен недоступен.' );
		}

		$participation = $this->participationRepo->find( $registration->participation_id );
		if ( ! $participation ) {
			throw new CodedException( ErrorCode::ExamAccess, 'Экзамен недоступен.' );
		}

		// Проверить что участие принадлежит пользователю
		$person = $this->personRepo->findByUserId( $wpUserId );
		if ( ! $person || $participation->student_person_id !== $person->id ) {
			throw new CodedException( ErrorCode::ExamAccess, 'Экзамен недоступен.' );
		}

		return new AttemptContext(
			audience: new ExamAudience( $participation->id ),
			participationId: $participation->id,
			registrationId: $registrationId,
			personId: $participation->student_person_id,
			wpUserId: $wpUserId
		);
	}

	/**
	 * Старт попытки экзамена (6.1.5).
	 *
	 * @param AttemptContext $ctx Контекст участника
	 *
	 * @return AttemptDTO
	 * @throws CodedException
	 */
	public function start( AttemptContext $ctx ): AttemptDTO {
		return $this->transactionRunner->inTransactionWithRetry( function () use ( $ctx ): AttemptDTO {
			// 1. Блокировка участия
			$participation = $this->participationRepo->findForUpdate( $ctx->participationId );
			if ( ! $participation ) {
				throw new CodedException( ErrorCode::ExamAccess, 'Экзамен недоступен.' );
			}

			// 2. Если уже есть текущая попытка — вернуть её
			if ( $participation->current_attempt_id ) {
				$attempt = $this->attemptRepo->find( $participation->current_attempt_id );
				if ( $attempt && $attempt->isExam() ) {
					return $attempt;
				}
			}

			// 3. Запись должна быть действующей
			$registration = $this->registrationRepo->find( $ctx->registrationId );
			if ( ! $registration || ! $registration->active_slot ) {
				throw new CodedException( ErrorCode::ExamAccess, 'Экзамен недоступен.' );
			}

			// 4. Сеанс и проведение не отменены
			$session = $this->sessionRepo->find( $registration->session_id );
			if ( ! $session || 'cancelled' === $session->status ) {
				throw new CodedException( ErrorCode::ExamAccess, 'Экзамен недоступен.' );
			}

			$event = $this->eventRepo->find( $session->event_id );
			if ( ! $event || 'cancelled' === $event->status ) {
				throw new CodedException( ErrorCode::ExamAccess, 'Экзамен недоступен.' );
			}

			$nowUtc = $this->time->nowUtc();
			$nowLocal = $this->time->nowLocal();

			// 5. Проверить что сеанс начался
			if ( $nowUtc < $session->scheduled_at ) {
				throw new CodedException( ErrorCode::ExamNotOpen, 'Экзамен ещё не начался.' );
			}

			// 6. Если сеанс закончился — неявка
			if ( $nowUtc >= $session->planned_end_at ) {
				$this->noShowService->markMissedLocked( $participation, $registration, $session );
				throw new CodedException( ErrorCode::ExamNotOpen, 'Время начала истекло.' );
			}

			// 7. Нет другой активной попытки
			$otherActive = $this->attemptRepo->findAnyActive( $ctx->personId );
			if ( $otherActive ) {
				throw new CodedException( ErrorCode::ExamConflict, 'Сначала завершите начатую работу.' );
			}

			// 8. Получить вариант и длительность
			$assessmentId = $session->assessment_id;
			$durationMinutes = $this->getDuration( $event, $session );

			// 9. Создать попытку
			$deadlineLocal = date( 'Y-m-d H:i:s', strtotime( "+{$durationMinutes} minutes", strtotime( $nowLocal ) ) );

			$attempt = new AttemptDTO(
				id: 0,
				assessmentId: $assessmentId,
				studentPersonId: $ctx->personId,
				wpUserId: $ctx->wpUserId,
				status: 'in_progress',
				startedAt: $nowLocal,
				deadline_at: $deadlineLocal,
				submittedAt: null,
				totalScore: null,
				maxScore: null,
				perTaskScores: null,
				attemptNumber: $this->attemptRepo->nextAttemptNumber( $ctx->personId, $assessmentId ),
				groupId: null,
				groupLessonId: null,
				examParticipationId: $ctx->participationId,
				examRegistrationId: $ctx->registrationId,
				resultVersion: 1,
				updatedAt: $nowLocal,
			);

			$attemptId = $this->attemptRepo->create( $attempt );
			$attempt = $attempt->withId( $attemptId );

			// 10. Установить текущую попытку в участии
			$this->participationRepo->setCurrentAttempt( $participation->id, $attemptId );

			// Записать first_started_at сеанса если пуст
			if ( ! $session->first_started_at ) {
				$this->sessionRepo->update(
					$session->id,
					array( 'first_started_at' => $nowUtc )
				);
			}

			// 11. Отправить событие
			$this->outbox->append( 'AttemptStarted', array(
				'attempt_id' => $attemptId,
				'participation_id' => $ctx->participationId,
				'registration_id' => $ctx->registrationId,
			) );

			return $attempt;
		} );
	}

	/**
	 * Завершить просроченную попытку (6.2.1).
	 *
	 * @param int $attemptId ID попытки
	 *
	 * @return bool Была ли попытка завершена
	 */
	public function finalizeExpired( int $attemptId ): bool {
		return $this->transactionRunner->inTransactionWithRetry( function () use ( $attemptId ): bool {
			$attempt = $this->attemptRepo->find( $attemptId );
			if ( ! $attempt || ! $attempt->isExam() ) {
				return false;
			}

			// Блокировка участия
			$participation = $this->participationRepo->findForUpdate( $attempt->examParticipationId );
			if ( ! $participation ) {
				return false;
			}

			// Перечитать попытку
			$attempt = $this->attemptRepo->find( $attemptId );
			if ( ! $attempt || 'in_progress' !== $attempt->status ) {
				return false;
			}

			// Проверить что дедлайн прошёл
			$nowLocal = $this->time->nowLocal();
			if ( $nowLocal < $attempt->deadline_at ) {
				return false;
			}

			// Завершить попытку с подстановкой дедлайна в submitted_at
			$this->attemptRepo->update( $attempt->id, array(
				'status' => 'submitted',
				'submitted_at' => $attempt->deadline_at,
			) );

			// Оценить
			$this->autoGradeService->gradeAttempt( $attempt->id );

			// Событие
			$this->outbox->append( 'AttemptSubmitted', array(
				'attempt_id' => $attempt->id,
				'auto' => true,
			) );

			return true;
		} );
	}

	/**
	 * Сохранить ответ (6.1.6).
	 *
	 * @param AttemptContext $ctx Контекст
	 * @param int            $attemptId ID попытки
	 * @param int            $taskId ID задания
	 * @param string         $text Ответ
	 *
	 * @throws CodedException
	 */
	public function saveAnswer( AttemptContext $ctx, int $attemptId, int $taskId, string $text ): void {
		$attempt = $this->attemptRepo->find( $attemptId );
		if ( ! $attempt || $attempt->examParticipationId !== $ctx->participationId ) {
			throw new CodedException( ErrorCode::AttemptNotFound, 'Попытка не найдена.' );
		}

		$nowLocal = $this->time->nowLocal();

		// Проверить дедлайн
		if ( $nowLocal >= $attempt->deadline_at ) {
			$this->finalizeExpired( $attemptId );
			throw new CodedException( ErrorCode::AttemptExpired, 'Время попытки истекло.' );
		}

		// Сохранить через основной сервис
		$this->attemptService->saveAnswerFor( $attempt, $taskId, $text );
	}

	/**
	 * Сдать попытку (6.1.6).
	 *
	 * @param AttemptContext $ctx Контекст
	 * @param int            $attemptId ID попытки
	 *
	 * @return AttemptDTO
	 * @throws CodedException
	 */
	public function submit( AttemptContext $ctx, int $attemptId ): AttemptDTO {
		return $this->transactionRunner->inTransactionWithRetry( function () use ( $ctx, $attemptId ): AttemptDTO {
			// Блокировка участия
			$participation = $this->participationRepo->findForUpdate( $ctx->participationId );
			if ( ! $participation ) {
				throw new CodedException( ErrorCode::AttemptNotFound, 'Попытка не найдена.' );
			}

			$attempt = $this->attemptRepo->find( $attemptId );
			if ( ! $attempt || $attempt->examParticipationId !== $ctx->participationId ) {
				throw new CodedException( ErrorCode::AttemptNotFound, 'Попытка не найдена.' );
			}

			$nowLocal = $this->time->nowLocal();

			// Проверить дедлайн и завершить если истёк
			if ( $nowLocal >= $attempt->deadline_at ) {
				$this->finalizeExpired( $attemptId );
				throw new CodedException( ErrorCode::AttemptExpired, 'Время попытки истекло.' );
			}

			// Сдать
			$attempt = $this->attemptService->submitFor( $attempt );

			// События
			$this->outbox->append( 'AttemptSubmitted', array(
				'attempt_id' => $attempt->id,
				'auto' => false,
			) );

			return $attempt;
		} );
	}

	/**
	 * Продлить попытку (6.2.4).
	 *
	 * @param int    $actorUserId Администратор
	 * @param int    $attemptId ID попытки
	 * @param int    $minutes Минут (1–120)
	 * @param string $reason Причина
	 *
	 * @return AttemptDTO
	 * @throws CodedException
	 */
	public function extend( int $actorUserId, int $attemptId, int $minutes, string $reason ): AttemptDTO {
		if ( $minutes < 1 || $minutes > 120 ) {
			throw new CodedException( ErrorCode::InvalidInput, 'Продление на 1–120 минут.' );
		}

		if ( empty( trim( $reason ) ) ) {
			throw new CodedException( ErrorCode::InvalidInput, 'Причина обязательна.' );
		}

		$attempt = $this->attemptRepo->find( $attemptId );
		if ( ! $attempt || ! $attempt->isExam() ) {
			throw new CodedException( ErrorCode::AttemptNotFound, 'Попытка не найдена.' );
		}

		if ( 'in_progress' !== $attempt->status ) {
			throw new CodedException( ErrorCode::InvalidInput, 'Попытка уже завершена.' );
		}

		$newDeadline = date( 'Y-m-d H:i:s', strtotime( "+{$minutes} minutes", strtotime( $attempt->deadline_at ) ) );

		$this->attemptRepo->update( $attempt->id, array(
			'deadline_at' => $newDeadline,
		) );

		$attempt = $attempt->withDeadline( $newDeadline );

		$this->outbox->append( 'AttemptExtended', array(
			'attempt_id' => $attemptId,
			'minutes' => $minutes,
			'reason' => $reason,
			'actor_user_id' => $actorUserId,
			'new_deadline' => $newDeadline,
		) );

		return $attempt;
	}

	/**
	 * Получить длительность попытки в минутах.
	 */
	private function getDuration( object $event, object $session ): int {
		$format = $this->formatRegistry->for( $event->subject_key, $event->direction );
		$snapshot = $this->eventRepo->snapshotFor( $event->id );

		if ( $snapshot && isset( $snapshot['duration_minutes'] ) ) {
			return (int) $snapshot['duration_minutes'];
		}

		if ( $format ) {
			return $format->durationMinutes;
		}

		return 180; // Фолбэк: 3 часа
	}
}
