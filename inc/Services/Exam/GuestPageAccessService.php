<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\DTO\Exam\ExamSourceDTO;
use Inc\DTO\Exam\GuestPageOutcome;
use Inc\Enums\Exam\ExamTokenPurpose;
use Inc\Enums\Wp\PageRoutes;
use Inc\Managers\Woo\WooCommerceManager;
use Inc\Repositories\WPDBRepositories\ExamGuestApplicationRepository;
use Inc\Repositories\WPDBRepositories\ExamSourceRepository;
use Inc\Services\Exam\Payment\WooExamAdapter;
use Inc\Services\Security\RateLimitService;
use Inc\Shared\PluginLogger;

/**
 * Политика доступа к страницам гостя: принимает нормализованный ввод и возвращает исход без HTTP-вывода.
 *
 * Первый перенесённый маршрут — приглашение/платёжная ссылка. Ключи, сессии и лимиты остаются
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
}
