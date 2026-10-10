<?php

declare( strict_types=1 );

namespace Inc\Services\Exam\Payment;

use Inc\DTO\Exam\ExamPaymentLinkDTO;
use Inc\Enums\Exam\ExamOutboxEvent;
use Inc\Enums\Exam\ExamPaymentState;
use Inc\Enums\Exam\GuestApplicationState;
use Inc\Repositories\WPDBRepositories\ExamGuestApplicationRepository;
use Inc\Repositories\WPDBRepositories\ExamPaymentLinkRepository;
use Inc\Services\Exam\ExamHoldService;
use Inc\Services\Exam\ExamOutbox;
use Inc\Services\Exam\ExamTime;

/**
 * Сверка заказов WooCommerce с заявками гостей (этап 11a.5.4): запись подтверждается по серверным данным заказа.
 *
 * Признак оплаты — только `WC_Order::is_paid()` (через {@see WooGateway}); возврат из банка, `pending`, `on-hold`, `failed` оплатой не считаются.
 * Метод **идемпотентен**: хуки `woocommerce_payment_complete` и `woocommerce_order_status_changed` приходят дважды и в любом порядке, а минутный
 * тик досверяет то, что хук не донёс. Подтверждение места, поздняя оплата и «нужна помощь» — целиком в {@see ExamHoldService::convert()}.
 * Refund API магазина никогда не вызывается; писем гостю нет.
 */
class ExamPaymentReconciler {

	/** Пауза между повторными сверками заявок, ждущих разбора сотрудником, мин. */
	private const NEEDS_RESOLUTION_RECHECK_MINUTES = 15;

	public function __construct(
		private readonly WooGateway $woo,
		private readonly ExamPaymentLinkRepository $links,
		private readonly ExamGuestApplicationRepository $applications,
		private readonly ExamHoldService $holds,
		private readonly ExamOutbox $outbox,
		private readonly ExamTime $time,
	) {}

	/**
	 * Сверяет все экзаменные позиции заказа.
	 *
	 * @throws \Throwable Ошибка базы при подтверждении: связь остаётся `pending`, событие `ReconcileFailed` записано, повтор — за тиком.
	 */
	public function reconcileOrder( int $orderId ): void {
		if ( ! $this->woo->isActive() ) {
			return;
		}

		foreach ( $this->links->listByOrder( $orderId ) as $link ) {
			$this->reconcileLink( $link );
		}
	}

	private function reconcileLink( ExamPaymentLinkDTO $link ): void {
		$now = $this->time->nowUtc();

		if ( $this->woo->isOrderPaid( $link->wcOrderId ) ) {
			if ( ExamPaymentState::Paid->value === $link->paymentState ) {
				return; // повтор события
			}

			$this->confirmPaid( $link, $now );
			return;
		}

		$status = $this->woo->orderStatus( $link->wcOrderId );
		if ( 'failed' === $status && ExamPaymentState::Failed->value !== $link->paymentState ) {
			$this->links->update( $link->id, array( 'payment_state' => ExamPaymentState::Failed->value, 'last_reconciled_at' => $now, 'updated_at' => $now ) );
		} elseif ( 'cancelled' === $status && ExamPaymentState::Cancelled->value !== $link->paymentState ) {
			$this->links->update( $link->id, array( 'payment_state' => ExamPaymentState::Cancelled->value, 'last_reconciled_at' => $now, 'updated_at' => $now ) );
		} elseif ( 'refunded' === $status && ExamPaymentState::Paid->value === $link->paymentState ) {
			// Возврат вручную при подтверждённой записи: запись не удаляется, расхождение разбирает сотрудник.
			$this->flagDiscrepancy( $link );
		}
		// Прочее (pending, on-hold): оплата не получена — состояние не меняем. Старый неуспешный заказ уже подтверждённую запись не трогает.
	}

	private function confirmPaid( ExamPaymentLinkDTO $link, string $now ): void {
		$application = $this->applications->find( $link->applicationId );
		if ( null === $application ) {
			return;
		}

		// Вторая оплата уже подтверждённой заявки: связь отмечается оплаченной, запись не меняется, сотрудник разбирает лишнюю оплату.
		if ( GuestApplicationState::Confirmed->value === $application->state && $this->hasOtherPaidLink( $link ) ) {
			$this->links->update( $link->id, array( 'payment_state' => ExamPaymentState::Paid->value, 'last_reconciled_at' => $now, 'updated_at' => $now ) );
			$this->outbox->add( ExamOutboxEvent::PaidNeedsResolution, 'guest_application', $application->id, $application->version, array(
				'application_id' => $application->id,
				'event_id'       => $application->eventId,
				'session_id'     => $application->sessionId,
				'reason'         => 'extra_payment',
			) );
			return;
		}

		try {
			$converted = $this->holds->convert( $link->applicationId, null );
		} catch ( \Throwable $e ) {
			$this->links->update( $link->id, array( 'last_reconciled_at' => $now, 'updated_at' => $now ) );
			$this->outbox->add( ExamOutboxEvent::ReconcileFailed, 'guest_application', $application->id, $application->version, array( 'application_id' => $application->id, 'order_id' => $link->wcOrderId ) );
			throw $e;
		}

		$this->links->update( $link->id, array( 'payment_state' => ExamPaymentState::Paid->value, 'last_reconciled_at' => $now, 'updated_at' => $now ) );

		// Оплата пришла, а заявка отменена сотрудником или помечена неявкой: запись не воскрешается, но деньги получены — нужна помощь.
		if ( in_array( $converted->state, array( GuestApplicationState::Cancelled->value, GuestApplicationState::Missed->value ), true ) ) {
			$this->outbox->add( ExamOutboxEvent::PaidNeedsResolution, 'guest_application', $converted->id, $converted->version, array(
				'application_id' => $converted->id,
				'event_id'       => $converted->eventId,
				'session_id'     => $converted->sessionId,
				'reason'         => 'payment_after_cancel',
			) );
		}
	}

	private function hasOtherPaidLink( ExamPaymentLinkDTO $link ): bool {
		foreach ( $this->links->findByApplication( $link->applicationId ) as $other ) {
			if ( $other->id !== $link->id && ExamPaymentState::Paid->value === $other->paymentState ) {
				return true;
			}
		}

		return false;
	}

	private function flagDiscrepancy( ExamPaymentLinkDTO $link ): void {
		$application = $this->applications->find( $link->applicationId );
		if ( null !== $application ) {
			$this->outbox->add( ExamOutboxEvent::ReconcileFailed, 'guest_application', $application->id, $application->version, array(
				'application_id' => $application->id,
				'order_id'       => $link->wcOrderId,
				'reason'         => 'refunded_in_shop',
			) );
		}
	}

	/**
	 * Раз в 15 минут подтверждает, что заказ заявки «нужна помощь» всё ещё оплачен, и обновляет `last_reconciled_at` — очередь оплат показывает это время.
	 *
	 * Только отметка времени: состояние заявки и связи не меняются, событий outbox нет — уведомление «требуется помощь» не повторяется (9.5.2).
	 * Заказ, который магазин больше не считает оплаченным (возврат), время не обновляет: «последняя сверка» не должна выглядеть свежей,
	 * когда заказ изменился. Выключенный WooCommerce — ничего не делает.
	 *
	 * @return int Сколько связей отмечено.
	 */
	public function refreshWaitingForHelp( int $limit = 50 ): int {
		if ( ! $this->woo->isActive() ) {
			return 0;
		}

		$now     = $this->time->nowUtc();
		$touched = 0;
		foreach ( $this->links->listWaitingForHelpForRecheck( $this->recheckBefore(), $limit ) as $link ) {
			if ( $this->woo->isOrderPaid( $link->wcOrderId ) ) {
				$this->links->update( $link->id, array( 'last_reconciled_at' => $now, 'updated_at' => $now ) );
				++$touched;
			}
		}

		return $touched;
	}

	/** Время, раньше которого связь заявки «нужна помощь» повторно не сверяется (раз в 15 минут, 11a.8.2). */
	public function recheckBefore(): string {
		return $this->time->addMinutes( $this->time->nowUtc(), -self::NEEDS_RESOLUTION_RECHECK_MINUTES );
	}
}
