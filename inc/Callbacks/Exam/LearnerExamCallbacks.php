<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Exam;

use Inc\Core\BaseController;
use Inc\DTO\Exam\RegistrationResultDTO;
use Inc\Enums\Log\ErrorCode;
use Inc\Enums\Wp\Nonce;
use Inc\Services\Exam\ExamRegistrationService;
use Inc\Services\Exam\ExamReviewProjection;
use Inc\Services\Exam\LearnerExamsService;
use Inc\Services\Profile\ProfileViewResolver;
use Inc\Shared\CodedException;
use Inc\Shared\PluginLogger;
use Inc\Shared\Traits\Sanitizer;

/**
 * AJAX экзаменов в кабинете ученика и родителя.
 *
 * Без capability (у ученика и родителя нет LMS-прав): доступ — нонс + владелец данных.
 * Ученик видит и меняет только себя, родитель — только читает данные своих детей
 * (`ProfileContext::resolveSubjectPersonId()`); записаться, перенести или отменить может
 * только сам ученик.
 */
class LearnerExamCallbacks extends BaseController {

	use Sanitizer;

	public function __construct(
		private readonly ProfileViewResolver $resolver,
		private readonly LearnerExamsService $exams,
		private readonly ExamRegistrationService $registration,
		private readonly ExamReviewProjection $reviews,
	) {
		parent::__construct();
	}

	public function ajaxGetLearnerExams(): void {
		Nonce::ExamLearner->verify();

		if ( ! is_user_logged_in() ) {
			$this->error( 'Требуется вход в кабинет.' );
			return;
		}

		$ctx      = $this->resolver->context( get_current_user_id() );
		$personId = $ctx->resolveSubjectPersonId( $this->sanitizeInt( 'student_person_id' ) );
		if ( null === $personId ) {
			$this->error( 'Профиль учащегося не найден.' );
			return;
		}

		$this->success( $this->exams->build( $personId, $ctx->readOnly ) );
	}

	public function ajaxRegisterForExam(): void {
		$personId = $this->writablePersonId();
		if ( null === $personId ) {
			return;
		}

		$sessionId  = $this->sanitizeInt( 'session_id' );
		$requestKey = $this->sanitizeKey( 'request_key' );
		if ( 0 === $sessionId || '' === $requestKey ) {
			$this->error( 'Недостаточно данных для записи.' );
			return;
		}

		$this->run( $personId, fn () => $this->registration->register( $personId, $sessionId, $requestKey ) );
	}

	public function ajaxChangeExamRegistration(): void {
		$personId = $this->writablePersonId();
		if ( null === $personId ) {
			return;
		}

		$sessionId  = $this->sanitizeInt( 'session_id' );
		$requestKey = $this->sanitizeKey( 'request_key' );
		if ( 0 === $sessionId || '' === $requestKey ) {
			$this->error( 'Недостаточно данных для переноса.' );
			return;
		}

		$version = $this->sanitizeIntOrNull( 'version' );

		$this->run( $personId, fn () => $this->registration->change( $personId, $sessionId, $requestKey, $version ) );
	}

	public function ajaxCancelExamRegistration(): void {
		$personId = $this->writablePersonId();
		if ( null === $personId ) {
			return;
		}

		$eventId    = $this->sanitizeInt( 'event_id' );
		$requestKey = $this->sanitizeKey( 'request_key' );
		if ( 0 === $eventId || '' === $requestKey ) {
			$this->error( 'Недостаточно данных для отмены.' );
			return;
		}

		$version = $this->sanitizeIntOrNull( 'version' );

		$this->run( $personId, fn () => $this->registration->cancelBySelf( $personId, $eventId, $requestKey, $version ) );
	}

	/**
	 * Разбор результата: ученик — своего, родитель — своего ребёнка. Попытка определяется участием
	 * этого человека в этом проведении; `attempt_id` из запроса не принимается вовсе.
	 */
	public function ajaxGetExamReview(): void {
		Nonce::ExamLearner->verify();

		if ( ! is_user_logged_in() ) {
			$this->error( 'Требуется вход в кабинет.' );
			return;
		}

		$ctx      = $this->resolver->context( get_current_user_id() );
		$personId = $ctx->resolveSubjectPersonId( $this->sanitizeInt( 'student_person_id' ) );
		$eventId  = $this->sanitizeInt( 'event_id' );

		$review = null !== $personId && 0 !== $eventId ? $this->reviews->forStudent( $personId, $eventId ) : null;
		if ( null === $review ) {
			// Чужое проведение и отсутствие участия отвечают одинаково и без подробностей.
			$this->fail( ErrorCode::ExamAccess, 'Результат недоступен.' );
			return;
		}

		$this->success( $review );
	}

	/**
	 * Person-id ученика для операций записи. Родитель и чужие роли отвечают отказом:
	 * записывается, переносит и отменяет только сам ученик.
	 */
	private function writablePersonId(): ?int {
		Nonce::ExamLearner->verify();

		if ( ! is_user_logged_in() ) {
			$this->error( 'Требуется вход в кабинет.' );
			return null;
		}

		$ctx = $this->resolver->context( get_current_user_id() );
		if ( $ctx->readOnly || null === $ctx->personId ) {
			$this->fail( ErrorCode::ExamAccess, 'Записываться и менять запись может только сам ученик.' );
			return null;
		}

		return $ctx->personId;
	}

	/**
	 * Выполняет операцию записи и отвечает обновлённым списком карточек; предупреждения операции
	 * (`lesson_overlap`: сеанс пришёлся на занятие) идут рядом с карточками, запись при этом создана.
	 * Отказ правила — код и текст сервиса; любой другой сбой — общий текст, подробности в журнал.
	 */
	private function run( int $personId, callable $operation ): void {
		try {
			$result = $operation();
		} catch ( CodedException $e ) {
			$this->fail( $e->errorCode, $e->getMessage() );
			return;
		} catch ( \Throwable $e ) {
			PluginLogger::exception( 'LearnerExamCallbacks', $e, array( 'person_id' => $personId ), true );
			$this->error( 'Не удалось выполнить действие. Попробуйте ещё раз.' );
			return;
		}

		$warnings = $result instanceof RegistrationResultDTO ? $result->warnings : array();

		$this->success( $this->exams->build( $personId, false ) + array( 'warnings' => $warnings ) );
	}
}
