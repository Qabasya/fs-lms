<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Exam;

use Inc\Core\BaseController;
use Inc\DTO\Exam\ExamEventDTO;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Services\Exam\ExamEventService;
use Inc\Services\Exam\ExamPlanService;
use Inc\Shared\CodedException;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * AJAX планирования экзаменов сотрудником (этап 4): план предмета, проведения, сеансы, публикация, отмена.
 *
 * Все действия — под `Capability::ManageExams` и nonce `ExamManage`; доступ к предмету и конкретному проведению проверяет сервис
 * (`ExamAccessGuard`): право открывает раздел, guard — своё проведение. Отказ сервиса с кодом уходит клиенту через `fail()` вместе
 * с кодом и номером инцидента; ошибка проверки формы — тем же путём. Времена в ответах — местные.
 */
class ExamEventCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly ExamPlanService $plans,
		private readonly ExamEventService $events,
	) {
		parent::__construct();
	}

	public function ajaxGetExamPlan(): void {
		$this->authorize( Nonce::ExamManage, Capability::ManageExams );

		$subjectKey = $this->requireKey( 'subject_key' );
		$eventId    = $this->sanitizeInt( 'event_id' );

		$this->run( fn (): array => $this->plans->build( get_current_user_id(), $subjectKey, $eventId > 0 ? $eventId : null ) );
	}

	public function ajaxSaveExamSession(): void {
		$this->authorize( Nonce::ExamManage, Capability::ManageExams );

		$eventId   = $this->requireInt( 'event_id' );
		$sessionId = $this->sanitizeInt( 'session_id' );
		$version   = $this->hasParam( 'version' ) ? $this->sanitizeInt( 'version' ) : null;
		$input     = array(
			'date'          => $this->sanitizeText( 'date' ),
			'time'          => $this->sanitizeText( 'time' ),
			'assessment_id' => $this->sanitizeInt( 'assessment_id' ),
			'room_id'       => $this->sanitizeInt( 'room_id' ),
		);

		$this->run( fn (): array => array(
			'session' => $this->plans->sessionPayload(
				$this->events->saveSession( get_current_user_id(), $eventId, $input, $sessionId > 0 ? $sessionId : null, $sessionId > 0 ? $version : null )
			),
		) );
	}

	public function ajaxDeleteExamSession(): void {
		$this->authorize( Nonce::ExamManage, Capability::ManageExams );

		$sessionId = $this->requireInt( 'session_id' );

		$this->run( function () use ( $sessionId ): array {
			$this->events->deleteSession( get_current_user_id(), $sessionId );

			return array( 'session_id' => $sessionId );
		} );
	}

	public function ajaxSaveExamEvent(): void {
		$this->authorize( Nonce::ExamManage, Capability::ManageExams );

		$eventId = $this->sanitizeInt( 'event_id' );
		$version = $this->hasParam( 'version' ) ? $this->sanitizeInt( 'version' ) : 0;
		$input   = array(
			'subject_key'                => $this->sanitizeKey( 'subject_key' ),
			'title'                      => $this->sanitizeText( 'title' ),
			'description'                => $this->sanitizeMultilineText( 'description' ),
			'period_from'                => $this->sanitizeText( 'period_from' ),
			'period_to'                  => $this->sanitizeText( 'period_to' ),
			'registration_opens_at'      => $this->sanitizeText( 'registration_opens_at' ),
			'registration_closes_at'     => $this->sanitizeText( 'registration_closes_at' ),
			'default_assessment_id'      => $this->sanitizeInt( 'default_assessment_id' ),
			'guest_registration_enabled' => $this->sanitizeBool( 'guest_registration_enabled' ),
		);

		$this->run( function () use ( $eventId, $version, $input ): array {
			$event = $eventId > 0
				? $this->events->updateEvent( get_current_user_id(), $eventId, $input, $version )
				: $this->events->createDraft( get_current_user_id(), $input );

			return array( 'event' => $this->plans->eventPayload( $event ) );
		} );
	}

	public function ajaxPublishExamEvent(): void {
		$this->authorize( Nonce::ExamManage, Capability::ManageExams );

		$eventId = $this->requireInt( 'event_id' );
		$version = $this->sanitizeInt( 'version' );

		$this->run( fn (): array => array( 'event' => $this->plans->eventPayload( $this->events->publish( get_current_user_id(), $eventId, $version ) ) ) );
	}

	public function ajaxMoveExamSession(): void {
		$this->authorize( Nonce::ExamManage, Capability::ManageExams );

		$sessionId = $this->requireInt( 'session_id' );
		$reason    = $this->sanitizeMultilineText( 'reason' );
		$version   = $this->sanitizeInt( 'version' );
		$input     = array(
			'date'    => $this->sanitizeText( 'date' ),
			'time'    => $this->sanitizeText( 'time' ),
			'room_id' => $this->sanitizeInt( 'room_id' ),
		);

		$this->run( fn (): array => array(
			'session' => $this->plans->sessionPayload( $this->events->moveSession( get_current_user_id(), $sessionId, $input, $reason, $version ) ),
		) );
	}

	public function ajaxCancelExamSession(): void {
		$this->authorize( Nonce::ExamManage, Capability::ManageExams );

		$sessionId = $this->requireInt( 'session_id' );
		$reason    = $this->sanitizeMultilineText( 'reason' );
		$version   = $this->sanitizeInt( 'version' );

		$this->run( function () use ( $sessionId, $reason, $version ): array {
			$this->events->cancelSession( get_current_user_id(), $sessionId, $reason, $version );

			return array( 'session_id' => $sessionId );
		} );
	}

	public function ajaxCancelExamEvent(): void {
		$this->authorize( Nonce::ExamManage, Capability::ManageExams );

		$eventId = $this->requireInt( 'event_id' );
		$reason  = $this->sanitizeMultilineText( 'reason' );
		$version = $this->sanitizeInt( 'version' );

		$this->run( function () use ( $eventId, $reason, $version ): array {
			$this->events->cancelEvent( get_current_user_id(), $eventId, $reason, $version );

			return array( 'event_id' => $eventId );
		} );
	}

	/**
	 * Выполняет действие и отвечает: успех — данными, отказ сервиса с кодом — `fail()`, прочая ошибка проверки — `error()`.
	 *
	 * @param callable():array<string, mixed> $action
	 */
	private function run( callable $action ): void {
		try {
			$this->success( $action() );
		} catch ( CodedException $e ) {
			$this->fail( $e->errorCode, $e->getMessage() );
		} catch ( \InvalidArgumentException $e ) {
			$this->error( $e->getMessage() );
		}
	}
}
