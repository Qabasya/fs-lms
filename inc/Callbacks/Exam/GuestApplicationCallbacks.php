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
use Inc\DTO\Exam\ExamSourceDTO;
use Inc\Services\Exam\ExamFormTrackingService;
use Inc\Services\Exam\GuestSessionService;
use Inc\Services\Exam\GuestPageAccessService;
use Inc\Shared\Traits\RequestContextProvider;
use Inc\Shared\Traits\Sanitizer;

/** AJAX гостевой записи и проверки оплаты. Допуск к форме проверяется по сессии приглашения. */
class GuestApplicationCallbacks extends BaseController {

	use RequestContextProvider;
	use Sanitizer;

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
	) {
		parent::__construct();
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

		$this->success(
			array(
				'redirect'        => $this->woo->cartUrl(),
				'hold_expires_at' => null !== $application->holdExpiresAt ? $this->time->toLocal( $application->holdExpiresAt ) : null,
				'seconds_left'    => null !== $application->holdExpiresAt ? $this->time->secondsUntil( $this->time->nowUtc(), $application->holdExpiresAt ) : 0,
			)
		);
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

	private function cookie( string $name ): string {
		$value = $this->sanitizeCookieText( $name );

		return strtolower( $value );
	}
}
