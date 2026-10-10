<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\DTO\Exam\ExamSourceDTO;
use Inc\DTO\Exam\ExamReportDTO;
use Inc\DTO\Exam\GuestPageOutcome;
use Inc\Enums\Exam\ExamTokenPurpose;
use Inc\Enums\Exam\ExamAudience;
use Inc\Enums\Exam\ExamRegistrationStatus;
use Inc\Enums\Wp\PageRoutes;
use Inc\Managers\Woo\WooCommerceManager;
use Inc\Repositories\WPDBRepositories\ExamGuestApplicationRepository;
use Inc\Repositories\WPDBRepositories\ExamSourceRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Repositories\WPDBRepositories\ExamRegistrationRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Repositories\WPDBRepositories\ExamReportRepository;
use Inc\Services\Exam\Payment\WooExamAdapter;
use Inc\Services\Security\RateLimitService;
use Inc\Shared\PluginLogger;

/**
 * Политика доступа к страницам гостя: принимает нормализованный ввод и возвращает исход без HTTP-вывода.
 *
 * Приглашение, вход, результат и школьный отчёт используют отдельные scope. Ключи, сессии и лимиты остаются
 * в существующих сервисах; cookies/headers/redirect исполняет GuestPageResponder.
 */
class GuestPageAccessService {

	public function __construct(
		private readonly ExamAccessTokenService $tokens,
		private readonly GuestSessionService $sessions,
		private readonly ExamSourceRepository $sources,
		private readonly RateLimitService $rateLimit,
		private readonly WooExamAdapter $adapter,
		private readonly WooCommerceManager $woo,
		private readonly ExamGuestApplicationRepository $applicationRepo,
		private readonly ExamTime $time,
		private readonly ExamFormTrackingService $formLog,
		private readonly ExamParticipationRepository $participations,
		private readonly ExamRegistrationRepository $registrations,
		private readonly ExamSessionRepository $sessionRepo,
		private readonly ExamReviewProjection $reviews,
		private readonly ExamReportRepository $reports,
		private readonly ExamReportService $reportService,
	) {}

	/** Обмен ключа/платёжный переход либо проверка приглашения из сессионной куки. */
	public function invitationPage( string $key, string $pay, string $cookie, string $ip ): GuestPageOutcome {
		if ( '' !== $pay ) {
			return $this->openPayment( $pay, $ip );
		}

		if ( '' !== $key ) {
			return $this->exchangeInvitation( $key, $ip );
		}

		$source = $this->currentSource( $cookie );
		if ( null === $source ) {
			return GuestPageOutcome::notFound();
		}
		$this->formLog->opened( $source );

		return GuestPageOutcome::render();
	}

	/** Источник действующего приглашения; входные cookies читает транспортный адаптер. */
	public function currentSource( string $cookie ): ?ExamSourceDTO {
		$id     = '' !== $cookie ? $this->sessions->resolveInvitation( $cookie ) : null;
		$source = null !== $id ? $this->sources->find( $id ) : null;

		return null !== $source && $source->isActive && null === $source->keyRevokedAt ? $source : null;
	}

	private function openPayment( string $key, string $ip ): GuestPageOutcome {
		if ( $this->rateLimit->isInvitationLocked( $ip ) ) {
			return GuestPageOutcome::notFound();
		}

		$token       = $this->tokens->exchange( ExamTokenPurpose::Payment, $key );
		$application = null !== $token ? $this->applicationRepo->find( $token->targetId ) : null;
		if ( null === $application || ! $application->isHeld || null === $application->holdExpiresAt || $application->holdExpiresAt <= $this->time->nowUtc() ) {
			$this->rateLimit->registerInvitationFailure( $ip );

			return GuestPageOutcome::notFound();
		}

		try {
			$this->adapter->addToCart( $application );
		} catch ( \Throwable $e ) {
			PluginLogger::exception( 'ExamGuestPayment', $e, array( 'application_id' => $application->id ), true );
			return GuestPageOutcome::notFound();
		}

		return GuestPageOutcome::redirect( $this->woo->cartUrl() );
	}

	private function exchangeInvitation( string $key, string $ip ): GuestPageOutcome {
		if ( $this->rateLimit->isInvitationLocked( $ip ) ) {
			return GuestPageOutcome::notFound();
		}

		$token  = $this->tokens->exchange( ExamTokenPurpose::Invitation, $key );
		$source = null !== $token ? $this->sources->find( $token->targetId ) : null;
		if ( null === $token || null === $source || ! $source->isActive || null !== $source->keyRevokedAt ) {
			$this->rateLimit->registerInvitationFailure( $ip );

			return GuestPageOutcome::notFound();
		}

		$cookie = $this->sessions->openInvitation( $source->id, $token->generation );

		// Редирект на адрес без ключа: в адресной строке, истории и запросах метрики ключа уже нет.
		return GuestPageOutcome::redirect( PageRoutes::ExamSignup->url(), array( GuestSessionService::COOKIE_INVITATION => $cookie ) );
	}


	/** Решение доступа без вывода HTTP-ответа. */
	public function entryPage( string $key, string $ip ): GuestPageOutcome {
		if ( '' !== $key ) {
			return $this->exchangeEntry( $key, $ip );
		}

		return null !== $this->sessions->current() ? GuestPageOutcome::render() : GuestPageOutcome::notFound();
	}

	/** Решение доступа без вывода HTTP-ответа. */
	public function resultPage( string $key, string $ip ): GuestPageOutcome {
		if ( '' !== $key ) {
			return $this->exchangeResult( $key, $ip );
		}

		$participationId = $this->sessions->viewableParticipationId();

		return null !== $participationId && $this->reviews->isParticipationRevealed( $participationId ) ? GuestPageOutcome::render() : GuestPageOutcome::notFound();
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

	private function exchangeEntry( string $key, string $ip ): GuestPageOutcome {
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

	/** Отчёт принимает только свою сессию и актуальное поколение ключа. */
	public function reportPage( string $key, string $ip ): GuestPageOutcome {
		if ( '' !== $key ) {
			return $this->exchangeReport( $key, $ip );
		}

		return null !== $this->currentReport() ? GuestPageOutcome::render() : GuestPageOutcome::notFound();
	}

	/** Действующий отчёт из сессии: допуск перечитывается при каждом обращении. */
	public function currentReport(): ?ExamReportDTO {
		$id     = $this->sessions->currentReportId();
		$report = null !== $id ? $this->reports->find( $id ) : null;

		return null !== $report && $this->reportService->isOpen( $report ) ? $report : null;
	}

	private function exchangeReport( string $key, string $ip ): GuestPageOutcome {
		if ( $this->rateLimit->isInvitationLocked( $ip ) ) {
			return GuestPageOutcome::notFound();
		}

		$token  = $this->tokens->exchange( ExamTokenPurpose::Report, $key );
		$report = null !== $token ? $this->reports->find( $token->targetId ) : null;
		if ( null === $token || null === $report || ! $this->reportService->isOpen( $report ) ) {
			$this->rateLimit->registerInvitationFailure( $ip );

			return GuestPageOutcome::notFound();
		}

		// Сессия живёт не дольше отчёта и не дольше суток: ссылка остаётся у школы, кука — нет.
		$day    = $this->time->addMinutes( $this->time->nowUtc(), 1440 );
		$cookie = $this->sessions->openReport( $token, min( $report->expiresAt, $day ) );

		return GuestPageOutcome::redirect( PageRoutes::ExamReport->url(), array( GuestSessionService::COOKIE_REPORT => $cookie ) );
	}
}
