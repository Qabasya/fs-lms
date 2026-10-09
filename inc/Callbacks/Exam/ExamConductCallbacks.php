<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Exam;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Export\ExportTarget;
use Inc\Enums\Log\ErrorCode;
use Inc\Enums\Wp\Nonce;
use Inc\Services\Exam\ExamApprovalService;
use Inc\Services\Exam\ExamAttemptService;
use Inc\Services\Exam\ExamConductService;
use Inc\Services\Exam\ExamRegistrationService;
use Inc\Services\Exam\GuestOnSiteService;
use Inc\Services\Export\ExportService;
use Inc\Shared\CodedException;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * AJAX проведения экзамена сотрудником (этап 8): доска сеанса и действия над участником.
 *
 * Все действия — под `Capability::ManageExams` и nonce `ExamManage`; доступ к конкретному проведению проверяет сервис
 * (`ExamAccessGuard::canManageEvent()`). Каждое изменяющее действие отвечает обновлённой доской сеанса: клиент
 * перерисовывает экран одним ответом.
 */
class ExamConductCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	/** Допустимое продление, мин: границы совпадают с `ExamAttemptService::extend()`, здесь — ранний отказ. */
	private const MIN_EXTENSION = 1;
	private const MAX_EXTENSION = 120;

	public function __construct(
		private readonly ExamConductService $conduct,
		private readonly ExamRegistrationService $registration,
		private readonly ExamAttemptService $attempts,
		private readonly ExamApprovalService $approval,
		private readonly ExportService $exports,
		private readonly GuestOnSiteService $onSite,
	) {
		parent::__construct();
	}

	public function ajaxGetExamConduct(): void {
		$this->authorize( Nonce::ExamManage, Capability::ManageExams );

		$sessionId = $this->sanitizeInt( 'session_id' );

		$this->run( function () use ( $sessionId ): array {
			$resolved = $sessionId > 0 ? $sessionId : $this->conduct->defaultSessionId( get_current_user_id() );
			if ( null === $resolved ) {
				return array( 'board' => null );
			}

			return array( 'board' => $this->conduct->sessionBoard( get_current_user_id(), $resolved ) );
		} );
	}

	public function ajaxCancelExamRegistrationByStaff(): void {
		$this->authorize( Nonce::ExamManage, Capability::ManageExams );

		$sessionId      = $this->requireInt( 'session_id' );
		$registrationId = $this->requireInt( 'registration_id' );
		$reason         = $this->sanitizeMultilineText( 'reason' );

		$this->runOnBoard( $sessionId, function () use ( $registrationId, $reason ): void {
			$this->registration->cancelByStaff( get_current_user_id(), $registrationId, $reason );
		} );
	}

	public function ajaxTransferExamRegistration(): void {
		$this->authorize( Nonce::ExamManage, Capability::ManageExams );

		$sessionId      = $this->requireInt( 'session_id' );
		$registrationId = $this->requireInt( 'registration_id' );
		$targetId       = $this->requireInt( 'target_session_id' );
		$reason         = $this->sanitizeMultilineText( 'reason' );

		$this->runOnBoard( $sessionId, function () use ( $registrationId, $targetId, $reason ): void {
			$this->registration->transferByStaff( get_current_user_id(), $registrationId, $targetId, $reason );
		} );
	}

	public function ajaxExtendExamAttempt(): void {
		$this->authorize( Nonce::ExamManage, Capability::ManageExams );

		$sessionId = $this->requireInt( 'session_id' );
		$attemptId = $this->requireInt( 'attempt_id' );
		$minutes   = $this->sanitizeInt( 'minutes' );
		$reason    = $this->sanitizeMultilineText( 'reason' );

		if ( $minutes < self::MIN_EXTENSION || $minutes > self::MAX_EXTENSION ) {
			$this->fail( ErrorCode::ExamConflict, sprintf( 'Продлить можно на срок от %d до %d минут.', self::MIN_EXTENSION, self::MAX_EXTENSION ) );
			return;
		}

		$this->runOnBoard( $sessionId, function () use ( $attemptId, $minutes, $reason ): void {
			$this->attempts->extend( get_current_user_id(), $attemptId, $minutes, $reason );
		} );
	}

	public function ajaxMarkExamArrival(): void {
		$this->authorize( Nonce::ExamManage, Capability::ManageExams );

		$sessionId      = $this->requireInt( 'session_id' );
		$registrationId = $this->requireInt( 'registration_id' );
		$arrived        = $this->sanitizeBool( 'arrived' );

		$this->runOnBoard( $sessionId, function () use ( $registrationId, $arrived ): void {
			$this->conduct->markArrival( get_current_user_id(), $registrationId, $arrived );
		} );
	}

	/**
	 * Массовое утверждение (8.5.3): каждая работа — в своей транзакции, частичный успех допустим.
	 * Ответ — число утверждённых, список пропущенных с причинами и (если передан сеанс) обновлённая доска.
	 */
	public function ajaxApproveExamAttempts(): void {
		$this->authorize( Nonce::ExamManage, Capability::ManageExams );

		$sessionId = $this->sanitizeInt( 'session_id' );
		$items     = array();
		foreach ( $this->unslashArray( 'items' ) as $item ) {
			if ( is_array( $item ) ) {
				$items[] = array(
					'attempt_id'     => $this->sanitizeIntValue( $item['attempt_id'] ?? 0 ),
					'result_version' => $this->sanitizeIntValue( $item['result_version'] ?? 0 ),
				);
			}
		}
		if ( array() === $items ) {
			$this->error( 'Выберите работы для утверждения.' );
			return;
		}

		$this->run( function () use ( $items, $sessionId ): array {
			$result = $this->approval->approveMany( get_current_user_id(), $items );
			if ( $sessionId > 0 ) {
				$result['board'] = $this->conduct->sessionBoard( get_current_user_id(), $sessionId );
			}

			return $result;
		} );
	}

	/**
	 * Исправление утверждённого результата (8.6): баллы по заданиям, причина, версия результата.
	 * Ответ — новая версия и итог: экран проверки перечитывает работу сам.
	 */
	public function ajaxCorrectExamResult(): void {
		$this->authorize( Nonce::ExamManage, Capability::ManageExams );

		$attemptId = $this->requireInt( 'attempt_id' );
		$reason    = $this->sanitizeMultilineText( 'reason' );
		$version   = $this->sanitizeInt( 'result_version' );
		$changes   = array();
		foreach ( $this->unslashArray( 'changes' ) as $change ) {
			if ( is_array( $change ) ) {
				$changes[] = array(
					'task_id'  => $this->sanitizeIntValue( $change['task_id'] ?? 0 ),
					'score'    => (float) str_replace( ',', '.', $this->sanitizeTextValue( $change['score'] ?? '0' ) ),
					'feedback' => $this->sanitizeTextValue( $change['feedback'] ?? '' ),
				);
			}
		}

		$this->run( function () use ( $attemptId, $changes, $reason, $version ): array {
			$updated = $this->approval->correct( get_current_user_id(), $attemptId, $changes, $reason, $version );

			return array( 'attempt_id' => $attemptId, 'total_score' => $updated->totalScore );
		} );
	}

	/**
	 * CSV участников сеанса или выбранных участий (8.9): ПД, поэтому обе проверки — раздел и право на выгрузку, затем доступ к
	 * проведению. Контактов в файле нет. Ссылка одноразовая, факт выгрузки пишется в журнал экспорта.
	 */
	public function ajaxExportExamParticipants(): void {
		$this->authorizeAll( Nonce::ExamManage, array( Capability::ManageLmsPlatform, Capability::ExportPII ) );

		$context = $this->exportContext();
		if ( array() === $context ) {
			$this->error( 'Выберите сеанс или участников.' );
			return;
		}

		$this->run( function () use ( $context ): array {
			// Доступ к проведению проверяется до выгрузки: чужой сеанс отказывает целиком, а не отдаёт пустой файл.
			$this->conduct->exportRows( get_current_user_id(), $context );

			return array( 'url' => $this->exports->run( ExportTarget::ExamParticipants, $context + array( 'actor_user_id' => get_current_user_id() ), isset( $context['participation_ids'] ) ? 'single' : 'bulk' ) );
		} );
	}

	/**
	 * Список для печати (8.9): ФИО, источник, сеанс. Те же права, что у выгрузки; ссылки входа и результата в ответ не попадают.
	 */
	public function ajaxGetExamPrintList(): void {
		$this->authorizeAll( Nonce::ExamManage, array( Capability::ManageLmsPlatform, Capability::ExportPII ) );

		$context = $this->exportContext();
		if ( array() === $context ) {
			$this->error( 'Выберите сеанс или участников.' );
			return;
		}

		$this->run( fn (): array => array(
			'rows' => array_map(
				static fn ( array $r ): array => array( 'name' => $r['name'], 'source' => $r['source'], 'session' => $r['session'] ),
				$this->conduct->exportRows( get_current_user_id(), $context )
			),
		) );
	}

	/**
	 * Выборка выгрузки из запроса: сеанс либо список участий.
	 *
	 * @return array<string, mixed>
	 */
	private function exportContext(): array {
		$sessionId = $this->sanitizeInt( 'session_id' );
		if ( $sessionId > 0 ) {
			return array( 'session_id' => $sessionId );
		}

		$ids = array_values( array_filter( $this->sanitizeIntList( 'participation_ids' ) ) );

		return array() === $ids ? array() : array( 'participation_ids' => $ids, 'ids' => $ids );
	}

	/**
	 * «Добавить гостя на месте» (11a.7): право `ManageExamGuests` и доступ к проведению (проверяет сервис).
	 * При совпадении ФИО или телефона с участником проведения — ответ `needs_confirmation`; повтор с `confirmed = 1` создаёт заявку.
	 * Ссылка на оплату возвращается один раз. Кнопок «оплачено», «бесплатно» и «наличные» нет.
	 */
	public function ajaxAddExamGuestOnSite(): void {
		$this->authorize( Nonce::ExamManage, Capability::ManageExamGuests );

		$sessionId  = $this->requireInt( 'session_id' );
		$sourceId   = $this->requireInt( 'source_id' );
		$requestKey = $this->sanitizeText( 'request_key' );
		$form       = array(
			'last_name'   => $this->sanitizeText( 'last_name' ),
			'first_name'  => $this->sanitizeText( 'first_name' ),
			'middle_name' => $this->sanitizeText( 'middle_name' ),
			'phone'       => $this->sanitizeText( 'phone' ),
			'messenger'   => $this->sanitizeText( 'messenger' ),
			'consents'    => $this->sanitizeKeyList( 'consents' ),
		);
		$confirmed  = $this->sanitizeBool( 'confirmed' );
		$ctx        = new \Inc\DTO\RequestContextDTO( '', '', get_current_user_id() );

		try {
			$this->success( $this->onSite->add( get_current_user_id(), $sessionId, $sourceId, $form, $ctx, $requestKey, $confirmed ) );
		} catch ( \Inc\Shared\GuestFormException $e ) {
			$this->fail( $e->errorCode, $e->getMessage(), array(), array( 'field' => $e->field ) );
		} catch ( CodedException $e ) {
			$this->fail( $e->errorCode, $e->getMessage() );
		}
	}

	/** Допуск гостя на площадке: ответ — обновлённая доска. */
	public function ajaxAdmitExamGuest(): void {
		$this->authorize( Nonce::ExamManage, Capability::ManageExamGuests );

		$participationId = $this->requireInt( 'participation_id' );
		$sessionId       = $this->requireInt( 'session_id' );
		$admitted        = $this->sanitizeBool( 'admitted' );

		$this->runOnBoard( $sessionId, fn () => $this->conduct->admit( get_current_user_id(), $participationId, $admitted ) );
	}

	/** «Выдать ссылку на вход»: адрес с ключом отдаётся один раз. */
	public function ajaxIssueExamEntryLink(): void {
		$this->authorize( Nonce::ExamManage, Capability::ManageExamGuests );

		$participationId = $this->requireInt( 'participation_id' );

		$this->run( fn (): array => array( 'url' => $this->conduct->issueEntryLink( get_current_user_id(), $participationId ) ) );
	}

	/** «Скопировать ссылку результата»: выдаёт новый ключ (прежний перестаёт работать). Право — `ShareExamResults`. */
	public function ajaxIssueExamResultLink(): void {
		$this->authorize( Nonce::ExamManage, Capability::ShareExamResults );

		$participationId = $this->requireInt( 'participation_id' );

		$this->run( fn (): array => array( 'url' => $this->conduct->issueResultLink( get_current_user_id(), $participationId ) ) );
	}

	/** «Отозвать ссылку результата»: ответ — обновлённая доска. */
	public function ajaxRevokeExamResultLink(): void {
		$this->authorize( Nonce::ExamManage, Capability::ShareExamResults );

		$participationId = $this->requireInt( 'participation_id' );
		$sessionId       = $this->requireInt( 'session_id' );

		$this->runOnBoard( $sessionId, fn () => $this->conduct->revokeResultLink( get_current_user_id(), $participationId ) );
	}

	/** «Скопировать ссылку на оплату»: перевыпуск ключа, прежняя ссылка перестаёт работать. */
	public function ajaxIssueExamGuestPayLink(): void {
		$this->authorize( Nonce::ExamManage, Capability::ManageExamGuests );

		$applicationId = $this->requireInt( 'application_id' );

		$this->run( fn (): array => $this->onSite->reissuePayLink( get_current_user_id(), $applicationId ) );
	}

	/**
	 * Выполняет действие и отвечает обновлённой доской сеанса.
	 *
	 * @param callable():void $action
	 */
	private function runOnBoard( int $sessionId, callable $action ): void {
		$this->run( function () use ( $sessionId, $action ): array {
			$action();

			return array( 'board' => $this->conduct->sessionBoard( get_current_user_id(), $sessionId ) );
		} );
	}

	/**
	 * Успех — данными, отказ сервиса с кодом — `fail()`, ошибка проверки ввода — `error()`.
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
