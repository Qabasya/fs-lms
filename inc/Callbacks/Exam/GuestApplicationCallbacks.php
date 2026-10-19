<?php
/**
 * AJAX гостевой записи и проверки оплаты. Допуск к форме проверяется по сессии приглашения.
 *
 * @package FS_LMS
 */

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
use Inc\Managers\Woo\WooCommerceManager;
use Inc\Services\Security\FormGuardService;
use Inc\Shared\CodedException;
use Inc\Shared\GuestFormException;
use Inc\Shared\PluginLogger;
use Inc\DTO\Exam\ExamGuestApplicationDTO;
use Inc\DTO\Exam\ExamFormTrackInputDTO;
use Inc\DTO\Exam\ExamSourceDTO;
use Inc\Enums\Exam\ExamFormEvent;
use Inc\Services\Security\RateLimitService;
use Inc\Services\Exam\ExamFormTrackingService;
use Inc\Services\Exam\GuestSessionService;
use Inc\Services\Exam\GuestPageAccessService;
use Inc\Shared\Traits\RequestContextProvider;
use Inc\Shared\Traits\Sanitizer;

/** AJAX гостевой записи и проверки оплаты. Допуск к форме проверяется по сессии приглашения. */
class GuestApplicationCallbacks extends BaseController {

	use RequestContextProvider;
	use Sanitizer;

	/** Получает обработку формы, бронь, Woo-переход и журналирование как отдельные ответственности. */
	public function __construct(
		private readonly GuestApplicationService $applications,
		private readonly WooExamAdapter $adapter,
		private readonly ExamHoldService $holds,
		private readonly FormGuardService $formGuard,
		private readonly WooCommerceManager $woo,
		private readonly ExamTime $time,
		private readonly GuestOrderStatusService $orderStatus,
		private readonly ExamPaymentReconciler $reconciler,
		private readonly ExamFormTrackingService $formLog,
		private readonly GuestPageAccessService $access,
		private readonly RateLimitService $rateLimit,
	) {
		parent::__construct();
	}

	/**
	 * Событие формы записи от браузера (`exam-signup-tracking.js`): журналы «Аутентификация» и «Экзамены».
	 *
	 * Ответ всегда успешный и пустой: трекинг ничего не сообщает гостю и ничего не ломает. Источник — только из куки
	 * приглашения; без неё (или при превышении лимита) событие молча отбрасывается.
	 */
	public function ajaxTrackExamSignupForm(): void {
		Nonce::ExamGuest->verify();

		$event  = ExamFormEvent::tryFrom( $this->sanitizeKey( 'event' ) );
		$source = null !== $event ? $this->currentSource() : null;

		if ( null === $source || ! $this->rateLimit->allowExamFormTrack( $this->requestContext()->ip ) ) {
			$this->success();
			return;
		}

		$this->formLog->track( $source, new ExamFormTrackInputDTO(
			event:   $event,
			visit:   substr( $this->sanitizeKey( 'visit' ), 0, 16 ),
			fields:  array_slice( $this->sanitizeKeyList( 'fields' ), 0, 30 ),
			filled:  max( 0, $this->sanitizeInt( 'filled' ) ),
			total:   max( 0, $this->sanitizeInt( 'total' ) ),
			seconds: max( 0, $this->sanitizeInt( 'seconds' ) ),
			submits: max( 0, $this->sanitizeInt( 'submits' ) ),
			message: $this->sanitizeText( 'message' ),
		) );

		$this->success();
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

		$visit       = substr( $this->sanitizeKey( 'visit' ), 0, 16 );
		$guardReason = $this->formGuard->rejectionReason( $this->sanitizeText( $this->formGuard->honeypotField() ), $this->sanitizeText( 'form_token' ) );
		if ( null !== $guardReason ) {
			$this->formLog->guardRejected( $source, $guardReason, $visit );
			$this->fail( ErrorCode::ExamReplay, 'Не удалось отправить форму. Обновите страницу.', array( 'guard_reason' => $guardReason ) );
			return;
		}

		$requestKey = $this->sanitizeText( 'request_key' );
		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{8,64}$/', $requestKey ) ) {
			$this->formLog->guardRejected( $source, 'bad_request_key', $visit );
			$this->fail( ErrorCode::ExamReplay, 'Не удалось отправить форму. Обновите страницу.', array( 'guard_reason' => 'bad_request_key' ) );
			return;
		}

		$form = array(
			'last_name'   => $this->sanitizeText( 'last_name' ),
			'first_name'  => $this->sanitizeText( 'first_name' ),
			'middle_name' => $this->sanitizeText( 'middle_name' ),
			'phone'       => $this->sanitizeText( 'phone' ),
			'messenger'   => $this->sanitizeText( 'messenger' ),
			'school'      => $this->sanitizeText( 'school' ),
			'session_id'  => $this->sanitizeInt( 'session_id' ),
			'consents'    => $this->sanitizeKeyList( 'consents' ),
		);

		try {
			$application = $this->applications->apply( $source, $form, $this->requestContext(), $requestKey );
		} catch ( GuestFormException $e ) {
			$this->formLog->invalid( $source, $e->field, $e->getMessage(), $visit );
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

		// Бесплатная ссылка преподавателя: бронь сразу становится записью, магазин не вызывается.
		if ( $source->isFree() ) {
			$this->confirmFree( $source, $application );
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

		// Тестовое окружение: оплата завершается автоматически, гость сразу попадает на страницу «Спасибо».
		$testUrl = null;
		try {
			$testUrl = $this->adapter->completeInTestEnv(
				$application,
				array( 'first_name' => $form['first_name'], 'last_name' => $form['last_name'], 'phone' => $form['phone'] )
			);
		} catch ( \Throwable $e ) {
			PluginLogger::exception( 'ExamGuest', $e, array( 'application_id' => $application->id ), true );
		}

		$this->success(
			array(
				'redirect'        => $testUrl ?? $this->woo->cartUrl(),
				'hold_expires_at' => null !== $application->holdExpiresAt ? $this->time->toLocal( $application->holdExpiresAt ) : null,
				'seconds_left'    => null !== $application->holdExpiresAt ? $this->time->secondsUntil( $this->time->nowUtc(), $application->holdExpiresAt ) : 0,
			)
		);
	}

	/**
	 * Запись по бесплатной ссылке: тот же путь подтверждения, что после оплаты (`convert()`), без заказа и без корзины.
	 * Не подтвердилось (сеанс закрыт, место ушло) — бронь снимается, клиент берёт новый `request_key`.
	 */
	private function confirmFree( ExamSourceDTO $source, ExamGuestApplicationDTO $application ): void {
		try {
			$confirmed = $this->holds->convert( $application->id, null );
		} catch ( \Throwable $e ) {
			PluginLogger::exception( 'ExamGuest', $e, array( 'application_id' => $application->id ), true );
			$confirmed = null;
		}

		if ( null === $confirmed || GuestApplicationState::Confirmed->value !== $confirmed->state ) {
			$this->holds->release( $application->id, GuestApplicationState::Failed );
			$this->fail( ErrorCode::ExamClosed, 'Не удалось записаться: место уже занято. Выберите дату заново.', array(), array( 'new_request_key' => true ) );
			return;
		}

		$this->formLog->holdCreated( $source, $application );
		$this->success( array( 'confirmed' => $this->orderStatus->confirmedFree( $confirmed->sessionId ) ) );
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
	private function currentSource(): ?ExamSourceDTO {
		return $this->access->currentSource( $this->cookie( GuestSessionService::COOKIE_INVITATION ) );
	}

	/**
	 * Нормализует hex-значение гостевой куки после чтения через Sanitizer.
	 */
	private function cookie( string $name ): string {
		$value = $this->sanitizeCookieText( $name );

		return strtolower( $value );
	}
}
