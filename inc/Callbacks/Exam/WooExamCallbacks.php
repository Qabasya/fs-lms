<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Exam;

use Inc\Core\BaseController;
use Inc\Services\Exam\ExamTime;
use Inc\Services\Exam\Payment\ExamPaymentReconciler;
use Inc\Services\Exam\Payment\GuestOrderStatusService;
use Inc\Services\Exam\Payment\WooExamAdapter;
use Inc\Services\Exam\Payment\WooGateway;
use Inc\Shared\PluginLogger;
use Inc\Shared\Traits\TemplateRenderer;

/**
 * Обработчики хуков классического WooCommerce для гостевых экзаменов (этап 11a.4–11a.5).
 *
 * Регистрирует их `WooExamController` только при активном WooCommerce. Магазин не меняется: корзина, оформление, шлюз, письма и страница
 * «Спасибо» остаются; LMS добавляет проверки, данные позиции, обратный отсчёт и блок статуса. Блочное оформление (Store API) не поддерживается.
 */
class WooExamCallbacks extends BaseController {

	use TemplateRenderer;

	public function __construct(
		private readonly WooExamAdapter $adapter,
		private readonly ExamPaymentReconciler $reconciler,
		private readonly GuestOrderStatusService $orderStatus,
		private readonly WooGateway $woo,
		private readonly ExamTime $time,
	) {
		parent::__construct();
	}

	/**
	 * Товар экзамена без данных заявки в корзину не попадает: прямая ссылка «в корзину» место не бронирует.
	 *
	 * @param mixed                $passed
	 * @param array<string, mixed> $cartItemData
	 */
	public function validateAddToCart( $passed, $productId = 0, $quantity = 1, $variationId = 0, $variations = array(), $cartItemData = array() ) {
		if ( ! $this->adapter->isAddAllowed( (int) $productId, (array) $cartItemData ) ) {
			$this->woo->notice( 'Сначала выберите дату: откройте ссылку-приглашение.' );

			return false;
		}

		return $passed;
	}

	/** Количество экзаменной позиции всегда 1 и не редактируется. @param mixed $html @param array<string, mixed> $item */
	public function fixQuantity( $html, $cartItemKey = '', $item = array() ) {
		return $this->adapter->isExamProduct( (int) ( $item['product_id'] ?? 0 ) ) ? '1' : $html;
	}

	/** @param mixed $passed @param array<string, mixed> $values */
	public function validateQuantityUpdate( $passed, $cartItemKey = '', $values = array(), $quantity = 1 ) {
		return $this->adapter->isExamProduct( (int) ( $values['product_id'] ?? 0 ) ) && 1 !== (int) $quantity ? false : $passed;
	}

	/**
	 * Участник, дата и время, срок брони под названием товара.
	 *
	 * @param array<int, array<string, string>> $itemData
	 * @param array<string, mixed>              $cartItem
	 *
	 * @return array<int, array<string, string>>
	 */
	public function itemData( $itemData, $cartItem = array() ) {
		foreach ( $this->adapter->itemDisplay( (array) $cartItem ) as $row ) {
			$itemData[] = $row;
		}

		return $itemData;
	}

	/** Позицию удалили — бронь снимается. @param string $cartItemKey @param \WC_Cart $cart */
	public function onItemRemoved( $cartItemKey, $cart ): void {
		$removed = is_object( $cart ) && isset( $cart->removed_cart_contents[ $cartItemKey ] ) ? (array) $cart->removed_cart_contents[ $cartItemKey ] : array();
		if ( $this->adapter->isExamProduct( (int) ( $removed['product_id'] ?? 0 ) ) ) {
			$this->adapter->onItemRemoved( $removed );
		}
	}

	/** Истёкшая или отменённая заявка убирается из корзины с понятным уведомлением. */
	public function checkCart(): void {
		if ( $this->adapter->cleanCart() ) {
			$this->woo->notice( 'Время брони истекло. Выберите дату заново.' );
		}
	}

	/** Привязка к заявке переносится в мету позиции заказа. @param \WC_Order_Item_Product $item @param array<string, mixed> $values */
	public function copyToOrderItem( $item, $cartItemKey = '', $values = array(), $order = null ): void {
		if ( null !== $this->adapter->applicationOfItem( (array) $values ) ) {
			$item->add_meta_data( WooExamAdapter::ORDER_ITEM_META, (int) $values[ WooExamAdapter::ITEM_APPLICATION ], true );
		}
	}

	/** Заказ сохранён: связи с заявками и этап «ожидается подтверждение оплаты». */
	public function onOrderProcessed( $orderId ): void {
		$this->guarded( (int) $orderId, fn () => $this->adapter->linkOrder( (int) $orderId ) );
	}

	/** `woocommerce_payment_complete` и `woocommerce_order_status_changed`: могут прийти дважды и в любом порядке — сверка идемпотентна. */
	public function onOrderChanged( $orderId ): void {
		$this->guarded( (int) $orderId, fn () => $this->reconciler->reconcileOrder( (int) $orderId ) );
	}

	/** Обратный отсчёт брони в корзине и на оформлении; время — с сервера, клиент только тикает. */
	public function renderHoldCountdown(): void {
		foreach ( $this->adapter->cartExamItems() as $item ) {
			$app = $this->adapter->applicationOfItem( array( WooExamAdapter::ITEM_APPLICATION => $item['application_id'], WooExamAdapter::ITEM_SIGNATURE => $item['signature'] ) );
			if ( null !== $app && $app->isHeld && null !== $app->holdExpiresAt ) {
				$this->render( 'frontend/exam-hold-countdown', array(
					'until'        => substr( $this->time->toLocal( $app->holdExpiresAt ), 11, 5 ),
					'seconds_left' => $this->time->secondsUntil( $this->time->nowUtc(), $app->holdExpiresAt ),
				) );
			}
		}
	}

	/** Блок статуса на странице «Спасибо» — только владельцу заказа (ключ заказа из адреса). */
	public function renderThankYou( $orderId ): void {
		$key    = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$blocks = $this->orderStatus->forOrder( (int) $orderId, $key );
		if ( array() !== $blocks ) {
			$this->render( 'frontend/exam-order-status', array( 'blocks' => $blocks, 'order_id' => (int) $orderId, 'order_key' => $key ) );
		}
	}

	/** Сбой сверки не должен ломать оформление заказа: пишем в журнал, повторит тик. */
	private function guarded( int $orderId, callable $action ): void {
		try {
			$action();
		} catch ( \Throwable $e ) {
			PluginLogger::exception( 'ExamPayment', $e, array( 'order_id' => $orderId ), true );
		}
	}
}
