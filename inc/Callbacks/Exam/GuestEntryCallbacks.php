<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Exam;

use Inc\Core\BaseController;
use Inc\DTO\Exam\GuestPageOutcome;
use Inc\Enums\Exam\ExamAudience;
use Inc\Enums\Exam\ExamRegistrationStatus;
use Inc\Enums\Exam\ExamTokenPurpose;
use Inc\Enums\Wp\Nonce;
use Inc\Enums\Wp\PageRoutes;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Repositories\WPDBRepositories\ExamRegistrationRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Services\Exam\ExamAccessTokenService;
use Inc\Services\Exam\ExamTime;
use Inc\Services\Exam\GuestEntryViewService;
use Inc\Services\Exam\GuestPageResponder;
use Inc\Services\Exam\GuestResultViewService;
use Inc\Services\Exam\GuestSessionService;
use Inc\Services\Security\RateLimitService;
use Inc\Shared\Traits\RequestContextProvider;
use Inc\Shared\Traits\Sanitizer;
use Inc\Shared\Traits\TemplateRenderer;

/**
 * Страница входа гостя на экзамен (этап 11b.1): ключ из ссылки сотрудника → кука → адрес без ключа.
 *
 * Гость **не становится пользователем WordPress**: личность — только гостевая сессия. Недействительный, истёкший, отозванный ключ
 * и ключ другого назначения — обычная 404 без намёков на существование записи; неудачи считаются в общий лимит IP (11a.2.5).
 */
class GuestEntryCallbacks extends BaseController {

	use RequestContextProvider;
	use Sanitizer;
	use TemplateRenderer;

	public function __construct(
		private readonly ExamAccessTokenService $tokens,
		private readonly GuestSessionService $sessions,
		private readonly ExamParticipationRepository $participations,
		private readonly ExamRegistrationRepository $registrations,
		private readonly ExamSessionRepository $sessionRepo,
		private readonly RateLimitService $rateLimit,
		private readonly GuestEntryViewService $view,
		private readonly ExamTime $time,
		private readonly GuestResultViewService $result,
		private readonly GuestPageResponder $responder,
	) {
		parent::__construct();
	}

	/** Обмен ключа на куку с редиректом на адрес без ключа либо проверка куки. */
	public function handleEntryPage(): GuestPageOutcome {
		$ip  = $this->requestContext()->ip;
		$key = strtolower( trim( $this->sanitizeText( 'k', 'GET' ) ) );

		if ( '' !== $key ) {
			return $this->exchange( $key, $ip );
		}

		return null !== $this->sessions->current() ? GuestPageOutcome::render() : GuestPageOutcome::notFound();
	}

	/** Шорткод страницы входа: HTML для сессии из куки; без сессии — пусто (страница уже отдала 404). */
	public function renderEntryPage(): string {
		$ctx  = $this->sessions->current();
		$data = null !== $ctx ? $this->view->build( $ctx ) : null;
		if ( null === $data ) {
			return '';
		}

		ob_start();
		$this->render( 'frontend/exam-entry', $data );

		return (string) ob_get_clean();
	}

	/**
	 * Страница результата: ключ результата → сессия `result` и редирект без ключа; иначе — участие из гостевой сессии
	 * (вход или результат). Попытка из адреса не читается; до сдачи, без сессии, после завершения сеанса — 404.
	 */
	public function handleResultPage(): GuestPageOutcome {
		$ip  = $this->requestContext()->ip;
		$key = strtolower( trim( $this->sanitizeText( 'k', 'GET' ) ) );

		if ( '' !== $key ) {
			return $this->exchangeResult( $key, $ip );
		}

		$participationId = $this->sessions->viewableParticipationId();
		$data            = null !== $participationId ? $this->result->build( $participationId ) : null;

		return null !== $data && true === $data['revealed'] ? GuestPageOutcome::render() : GuestPageOutcome::notFound();
	}

	/** Шорткод страницы результата. */
	public function renderResultPage(): string {
		$participationId = $this->sessions->viewableParticipationId();
		$data            = null !== $participationId ? $this->result->build( $participationId ) : null;
		if ( null === $data || true !== $data['revealed'] ) {
			return '';
		}

		$data['can_end_session'] = true;
		$data['crumbs']          = array(
			array( 'label' => __( 'Главная', 'fs-lms' ), 'url' => home_url( '/' ) ),
			array( 'label' => __( 'Результат экзамена', 'fs-lms' ), 'current' => true ),
		);

		ob_start();
		$this->render( 'frontend/exam-result', $data );

		return (string) ob_get_clean();
	}

	/**
	 * «Завершить сеанс» (публичный экшен, nonce `ExamGuest`): отзыв сессий и удаление кук. Пока работа не сдана — отказ:
	 * выйти из экзамена можно, только сдав работу.
	 */
	public function ajaxEndExamGuestSession(): void {
		Nonce::ExamGuest->verify();

		$participationId = $this->sessions->viewableParticipationId();
		$data            = null !== $participationId ? $this->result->build( $participationId ) : null;
		if ( null === $data || true !== $data['revealed'] ) {
			$this->error( 'Завершить сеанс можно после сдачи работы.' );
			return;
		}

		foreach ( array( GuestSessionService::COOKIE_ENTRY, GuestSessionService::COOKIE_RESULT ) as $name ) {
			$value = isset( $_COOKIE[ $name ] ) ? sanitize_text_field( wp_unslash( (string) $_COOKIE[ $name ] ) ) : '';
			if ( '' !== $value ) {
				$this->sessions->revoke( $value );
			}
		}
		$this->responder->clearCookies( array( GuestSessionService::COOKIE_ENTRY, GuestSessionService::COOKIE_RESULT ) );

		$this->success( array( 'url' => home_url( '/' ) ) );
	}

	private function exchangeResult( string $key, string $ip ): GuestPageOutcome {
		if ( $this->rateLimit->isInvitationLocked( $ip ) ) {
			return GuestPageOutcome::notFound();
		}

		$token         = $this->tokens->exchange( ExamTokenPurpose::Result, $key );
		$participation = null !== $token ? $this->participations->find( $token->targetId ) : null;
		if ( null === $token || null === $participation || null === $participation->currentAttemptId || ExamAudience::Guest->value !== $participation->audience ) {
			$this->rateLimit->registerInvitationFailure( $ip );

			return GuestPageOutcome::notFound();
		}

		// Сессия результата живёт не дольше ключа и не дольше суток: ссылка у гостя остаётся, кука — нет.
		$now     = $this->time->nowUtc();
		$day     = $this->time->addMinutes( $now, 1440 );
		$expires = null !== $token->expiresAt && $token->expiresAt < $day ? $token->expiresAt : $day;
		$cookie  = $this->sessions->openResult( $token, $expires );

		return GuestPageOutcome::redirect( PageRoutes::ExamResult->url(), array( GuestSessionService::COOKIE_RESULT => $cookie ) );
	}

	private function exchange( string $key, string $ip ): GuestPageOutcome {
		if ( $this->rateLimit->isInvitationLocked( $ip ) ) {
			return GuestPageOutcome::notFound();
		}

		$token         = $this->tokens->exchange( ExamTokenPurpose::Entry, $key );
		$participation = null !== $token ? $this->participations->find( $token->targetId ) : null;
		$registration  = null !== $participation && null !== $participation->activeRegistrationId ? $this->registrations->find( $participation->activeRegistrationId ) : null;
		$session       = null !== $registration ? $this->sessionRepo->find( $registration->sessionId ) : null;

		if ( null === $token || null === $participation || null === $registration || null === $session
			|| 1 !== $registration->activeSlot || ExamRegistrationStatus::Confirmed->value !== $registration->status
			|| null === $participation->admittedAt || $session->plannedEndAt <= $this->time->nowUtc() ) {
			$this->rateLimit->registerInvitationFailure( $ip );

			return GuestPageOutcome::notFound();
		}

		$cookie = $this->sessions->openEntry( $token, $registration->id, $session->plannedEndAt );

		return GuestPageOutcome::redirect( PageRoutes::ExamEntry->url(), array( GuestSessionService::COOKIE_ENTRY => $cookie ) );
	}
}
