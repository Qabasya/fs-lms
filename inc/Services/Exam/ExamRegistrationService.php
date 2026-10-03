<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\Repositories\WPDBRepositories\ExamRegistrationRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Enums\Log\ErrorCode;
use Inc\Shared\CodedException;

class ExamRegistrationService {

	public function __construct(
		private ExamRegistrationRepository $regRepo,
		private ExamSessionRepository $sessionRepo,
		private ExamParticipationRepository $participationRepo,
		private PersonRepository $personRepo,
	) {}

	/**
	 * Регистрирует ученика на сеанс (5.3)
	 * @throws CodedException
	 */
	public function registerParticipant( int $personId, int $sessionId, string $requestKey ): void {
		$person = $this->personRepo->findById( $personId );
		if ( ! $person ) {
			throw new CodedException( ErrorCode::ExamAccess, 'Ученик не найден' );
		}

		// Получить или создать participation
		$participation = $this->participationRepo->findByEventAndStudent( $sessionId, $personId );
		if ( ! $participation ) {
			throw new CodedException( ErrorCode::ExamAccess, 'Событие не найдено' );
		}

		// Проверить сеанс и место
		$session = $this->sessionRepo->find( $sessionId );
		if ( ! $session ) {
			throw new CodedException( ErrorCode::ExamAccess, 'Сеанс не найден' );
		}

		// Атомарная проверка места
		if ( $session->occupied_count >= $session->capacity ) {
			throw new CodedException( ErrorCode::ExamFull, 'Нет свободных мест' );
		}

		// Создать запись
		$this->regRepo->insert( array(
			'participation_id'  => $participation->id,
			'session_id'        => $sessionId,
			'status'            => 'confirmed',
			'active_slot'       => 1,
			'created_at'        => current_time( 'mysql', true ),
			'updated_at'        => current_time( 'mysql', true ),
		) );

		// Увеличить счётчик занятых мест
		$this->sessionRepo->update( $sessionId, array(
			'occupied_count' => $session->occupied_count + 1,
		) );
	}

	/**
	 * Переносит запись ученика на другой сеанс (5.3)
	 * @throws CodedException
	 */
	public function transferRegistration( int $personId, int $newSessionId, string $requestKey ): void {
		$person = $this->personRepo->findById( $personId );
		if ( ! $person ) {
			throw new CodedException( ErrorCode::ExamAccess, 'Ученик не найден' );
		}

		// Найти текущую запись
		$currentReg = $this->regRepo->findActiveByEventAndStudent( 0, $personId );
		if ( ! $currentReg ) {
			throw new CodedException( ErrorCode::ExamAccess, 'Активная запись не найдена' );
		}

		// Проверить новый сеанс
		$oldSession = $this->sessionRepo->find( $currentReg['session_id'] );
		$newSession = $this->sessionRepo->find( $newSessionId );

		if ( ! $newSession || $newSession->occupied_count >= $newSession->capacity ) {
			throw new CodedException( ErrorCode::ExamFull, 'Нет свободных мест в новом сеансе' );
		}

		// Отметить старую запись как перенесённую
		$this->regRepo->update( $currentReg['id'], array(
			'active_slot'    => null,
			'status'         => 'transferred',
			'updated_at'     => current_time( 'mysql', true ),
		) );

		// Создать новую запись
		$this->regRepo->insert( array(
			'participation_id'  => $currentReg['participation_id'],
			'session_id'        => $newSessionId,
			'status'            => 'confirmed',
			'active_slot'       => 1,
			'created_at'        => current_time( 'mysql', true ),
			'updated_at'        => current_time( 'mysql', true ),
		) );

		// Обновить счётчики
		if ( $oldSession ) {
			$this->sessionRepo->update( $currentReg['session_id'], array(
				'occupied_count' => max( 0, $oldSession->occupied_count - 1 ),
			) );
		}
		$this->sessionRepo->update( $newSessionId, array(
			'occupied_count' => $newSession->occupied_count + 1,
		) );
	}

	/**
	 * Отменяет запись студентом (5.3)
	 * @throws CodedException
	 */
	public function cancelBySelf( int $personId, int $eventId, string $requestKey ): void {
		$person = $this->personRepo->findById( $personId );
		if ( ! $person ) {
			throw new CodedException( ErrorCode::ExamAccess, 'Ученик не найден' );
		}

		// Получить участие события
		$participation = $this->participationRepo->findByEventAndStudent( $eventId, $personId );
		if ( ! $participation ) {
			throw new CodedException( ErrorCode::ExamAccess, 'Участие не найдено' );
		}

		// Получить активную запись
		$registration = $this->regRepo->findActive( $participation->id );
		if ( ! $registration ) {
			throw new CodedException( ErrorCode::ExamAccess, 'Активная запись не найдена' );
		}

		// Отметить как отменённую
		$this->regRepo->update( $registration->id, array(
			'active_slot'    => null,
			'status'         => 'cancelled_by_student',
			'updated_at'     => current_time( 'mysql', true ),
		) );

		// Освободить место в сеансе
		$session = $this->sessionRepo->find( $registration->session_id );
		if ( $session ) {
			$this->sessionRepo->update( $registration->session_id, array(
				'occupied_count' => max( 0, $session->occupied_count - 1 ),
			) );
		}
	}
}
