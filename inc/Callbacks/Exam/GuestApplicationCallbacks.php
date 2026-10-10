<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Exam;

use Inc\Core\BaseController;
use Inc\Enums\Exam\GuestApplicationState;
use Inc\Enums\Log\ErrorCode;
use Inc\Enums\Wp\Nonce;
use Inc\Services\Exam\ExamHoldService;
use Inc\Services\Exam\ExamTime;
use Inc\Services\Exam\GuestApplicationService;
use Inc\Services\Exam\Payment\ExamPaymentReconciler;
use Inc\Services\Exam\Payment\GuestOrderStatusService;
use Inc\Services\Exam\Payment\WooExamAdapter;
use Inc\Services\Exam\Payment\WooGateway;
use Inc\Services\Security\FormGuardService;
use Inc\Shared\CodedException;
use Inc\Shared\GuestFormException;
use Inc\Shared\PluginLogger;
use Inc\DTO\Exam\ExamSourceDTO;
use Inc\DTO\Exam\GuestPageOutcome;
use Inc\Enums\Exam\ExamTokenPurpose;
use Inc\Enums\Wp\PageRoutes;
use Inc\Repositories\WPDBRepositories\ExamGuestApplicationRepository;
use Inc\Repositories\WPDBRepositories\ExamSourceRepository;
use Inc\Services\Exam\ExamAccessTokenService;
use Inc\Services\Exam\ExamFormTrackingService;
use Inc\Services\Exam\GuestSessionService;
use Inc\Services\Exam\GuestSignupViewService;
use Inc\Services\Security\RateLimitService;
use Inc\Shared\Traits\RequestContextProvider;
use Inc\Shared\Traits\Sanitizer;
use Inc\Shared\Traits\TemplateRenderer;

/**
 * Гостевая форма записи на экзамен (этап 11a): страница приглашения и её обработка.
 *
 * Страница открывается только по ссылке школы с ключом: ключ обменивается на сессионную куку и исчезает из адреса;
 * без действующей куки, с неверным, отозванным или чужим ключом — обычная 404 темы без сведений о проведении.
 * Успешные открытия лимитом не считаются (за общим адресом школы их десятки); неудачные — считаются (20 за 15 минут на IP).
 * Методы возвращают {@see GuestPageOutcome}; исполняет его контроллер через `GuestPageResponder` — логика проверяется без `exit`.
 */
class GuestApplicationCallbacks extends BaseController {

	use RequestContextProvider;
	use Sanitizer;
	use TemplateRenderer;

	public function __construct(
		private readonly ExamAccessTokenService $tokens,
		private readonly GuestSessionService $sessions,
		private readonly ExamSourceRepository $sources,
		private readonly RateLimitService $rateLimit,
		private readonly GuestSignupViewService $view,
		private readonly GuestApplicationService $applications,
		private readonly WooExamAdapter $adapter,
		private readonly ExamHoldService $holds,
		private readonly FormGuardService $formGuard,
		private readonly WooGateway $woo,
		private readonly ExamTime $time,
		private readonly GuestOrderStatusService $orderStatus,
		private readonly ExamPaymentReconciler $reconciler,
		private readonly ExamGuestApplicationRepository $applicationRepo,
		private readonly ExamFormTrackingService $formLog,
	) {
		parent::__construct();
	}

	/** Обработка страницы формы: обмен ключа на куку (с редиректом на адрес без ключа) либо проверка куки. */
	public function handleInvitationPage(): GuestPageOutcome {
		$ip  = $this->requestContext()->ip;
		$key = strtolower( trim( $this->sanitizeText( 'k', 'GET' ) ) );
		$pay = strtolower( trim( $this->sanitizeText( 'pay', 'GET' ) ) );

		if ( '' !== $pay ) {
			return $this->openPayment( $pay, $ip );
		}

		if ( '' !== $key ) {
			return $this->exchangeInvitation( $key, $ip );
		}

		$source = $this->currentSource();
		if ( null === $source ) {
			return GuestPageOutcome::notFound();
		}
		$this->formLog->opened( $source );

		return GuestPageOutcome::render();
	}

	/** Шорткод формы: HTML страницы для источника из куки; без источника — пусто (страница уже отдала 404). */
	public function renderSignupPage(): string {
		$source = $this->currentSource();
		if ( null === $source ) {
			return '';
		}

		ob_start();
		$this->render( 'frontend/exam-signup', array_merge( $this->view->build( $source ), array( 'form_url' => PageRoutes::ExamSignup->url() ) ) );

		return (string) ob_get_clean();
	}

	/**
	 * «Перейти к оплате» (публичный экшен, nonce `ExamGuest`): бронь и корзина.
	 *
	 * Порядок: источник — только из куки приглашения → honeypot и метка времени → `request_key` → заявка с бронью (лимиты и проверки в сервисе) →
	 * корзина WooCommerce. Транзакция брони закрыта **до** обращения к магазину; сбой корзины освобождает бронь ровно один раз, и клиент
	 * после такой ошибки берёт новый `request_key`. Школа, класс и цена из запроса игнорируются.
	 */
	public function ajaxSubmitExamGuestApplication(): void {
		Nonce::ExamGuest->verify();

		$source = $this->currentSource();
		if ( null === $source ) {
			$this->fail( ErrorCode::ExamLink, 'Откройте форму по ссылке из приглашения.' );
			return;
		}

		if ( ! $this->formGuard->isHuman( $this->sanitizeText( $this->formGuard->honeypotField() ), $this->sanitizeText( 'form_token' ) ) ) {
			$this->formLog->invalid( $source, '', 'Защита формы отклонила отправку' );
			$this->fail( ErrorCode::ExamReplay, 'Не удалось отправить форму. Обновите страницу.' );
			return;
		}

		$requestKey = $this->sanitizeText( 'request_key' );
		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{8,64}$/', $requestKey ) ) {
			$this->fail( ErrorCode::ExamReplay, 'Не удалось отправить форму. Обновите страницу.' );
			return;
		}

		$form = array(
			'last_name'   => $this->sanitizeText( 'last_name' ),
			'first_name'  => $this->sanitizeText( 'first_name' ),
			'middle_name' => $this->sanitizeText( 'middle_name' ),
			'phone'       => $this->sanitizeText( 'phone' ),
			'messenger'   => $this->sanitizeText( 'messenger' ),
			'session_id'  => $this->sanitizeInt( 'session_id' ),
			'consents'    => $this->sanitizeKeyList( 'consents' ),
		);

		try {
			$application = $this->applications->apply( $source, $form, $this->requestContext(), $requestKey );
		} catch ( GuestFormException $e ) {
			$this->formLog->invalid( $source, $e->field, $e->getMessage() );
			$extra = array( 'field' => $e->field );
			if ( 'cabinet' === $e->field ) {
				$extra['cabinet_url'] = \Inc\Enums\Wp\PageRoutes::UserProfile->screenUrl( 'learner-exams' );
			}
			$this->fail( $e->errorCode, $e->getMessage(), array(), $extra );
			return;
		} catch ( CodedException $e ) {
			if ( ErrorCode::ExamLimit === $e->errorCode ) {
				$this->formLog->limited( $source, $e->getMessage() );
			}
			$this->fail( $e->errorCode, $e->getMessage() );
			return;
		}

		if ( ! $application->isHeld || GuestApplicationState::Failed->value === $application->state ) {
			$this->fail( ErrorCode::ExamClosed, 'Время брони истекло. Выберите дату заново.', array(), array( 'new_request_key' => true ) );
			return;
		}

		try {
			$this->adapter->addToCart( $application );
		} catch ( \Throwable $e ) {
			// Компенсация: место освобождается ровно один раз; форма остаётся заполненной.
			$this->holds->release( $application->id, GuestApplicationState::Failed );
			$message = $e instanceof CodedException ? $e->getMessage() : 'Не удалось перейти к оплате. Попробуйте ещё раз.';
			if ( ! $e instanceof CodedException ) {
				PluginLogger::exception( 'ExamGuest', $e, array( 'application_id' => $application->id ), true );
			}
			$this->fail( $e instanceof CodedException ? $e->errorCode : ErrorCode::ExamClosed, $message, array(), array( 'new_request_key' => true ) );
			return;
		}

		$this->formLog->holdCreated( $source, $application );

		$this->success( array(
			'redirect'        => $this->woo->cartUrl(),
			'hold_expires_at' => null !== $application->holdExpiresAt ? $this->time->toLocal( $application->holdExpiresAt ) : null,
			'seconds_left'    => null !== $application->holdExpiresAt ? $this->time->secondsUntil( $this->time->nowUtc(), $application->holdExpiresAt ) : 0,
		) );
	}

	/**
	 * «Проверить статус» на странице «Спасибо»: ключ заказа → сверка → текущий статус. Только владельцу заказа;
	 * по одному номеру заказа или телефону ничего не отдаётся.
	 */
	public function ajaxCheckExamApplicationStatus(): void {
		Nonce::ExamGuest->verify();

		$orderId = $this->sanitizeInt( 'order_id' );
		$key     = $this->sanitizeText( 'key' );
		if ( $orderId <= 0 || '' === $key || array() === $this->orderStatus->forOrder( $orderId, $key ) ) {
			$this->fail( ErrorCode::ExamLink, 'Заказ не найден.' );
			return;
		}

		try {
			$this->reconciler->reconcileOrder( $orderId );
		} catch ( \Throwable $e ) {
			PluginLogger::exception( 'ExamGuest', $e, array( 'order_id' => $orderId ), true );
		}

		$this->success( array( 'blocks' => $this->orderStatus->forOrder( $orderId, $key ) ) );
	}

	/** Источник по куке приглашения; null — куки нет, сессия отозвана, истекла или ссылка перевыпущена; источник отключён или отозван. */
	public function currentSource(): ?ExamSourceDTO {
		$cookie = $this->cookie( GuestSessionService::COOKIE_INVITATION );
		$id     = '' !== $cookie ? $this->sessions->resolveInvitation( $cookie ) : null;
		$source = null !== $id ? $this->sources->find( $id ) : null;

		return null !== $source && $source->isActive && null === $source->keyRevokedAt ? $source : null;
	}

	/**
	 * Ссылка на оплату от сотрудника (`?pay=`, 11a.7.5): ключ назначения «оплата» → заявка держит место → позиция в корзину гостя → редирект в корзину.
	 * Недействительный ключ, истёкшая или отменённая заявка, ключ другого назначения — обычная 404 (неудача засчитывается в лимит IP).
	 * Повторное открытие ссылки вторую позицию не кладёт (проверка внутри адаптера).
	 */
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

	private function cookie( string $name ): string {
		$value = isset( $_COOKIE[ $name ] ) ? sanitize_text_field( wp_unslash( (string) $_COOKIE[ $name ] ) ) : '';

		return strtolower( $value );
	}
}
