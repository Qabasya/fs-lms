<?php

declare( strict_types=1 );

namespace Inc\Managers\Woo;

use Inc\Enums\Exam\ExamOrderItemMeta;

/**
 * Единственная тонкая обёртка над функциями WooCommerce для экзаменов (этап 11a).
 *
 * Весь остальной код экзаменов WooCommerce не знает и работает с этим классом; в тестах он подменяется моком.
 * **Заказы и товары — только через CRUD WooCommerce** (`wc_get_product()`, `wc_get_order()`): на проде HPOS без режима совместимости,
 * поэтому `get_post_meta`, `wp_posts` и прямые запросы к таблицам заказов запрещены.
 * Метод, вызванный при неактивном WooCommerce, ничего не делает и отвечает «нет данных».
 */
class WooCommerceManager {

	/** Удалённая позиция корзины для хука WooCommerce. @return array<string, mixed> */
	public function removedCartItem( mixed $cart, string $key ): array {
		return is_object( $cart ) && isset( $cart->removed_cart_contents[ $key ] ) ? (array) $cart->removed_cart_contents[ $key ] : array();
	}

	public function isActive(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
	}

	/**
	 * Сведения о товаре или null, если товара нет.
	 *
	 * @return array{id: int, name: string, virtual: bool, purchasable: bool, price: string}|null
	 */
	public function product( int $productId ): ?array {
		if ( ! $this->isActive() || $productId <= 0 ) {
			return null;
		}

		$product = wc_get_product( $productId );
		if ( ! $product instanceof \WC_Product ) {
			return null;
		}

		return array(
			'id'          => $product->get_id(),
			'name'        => $product->get_name(),
			'virtual'     => $product->is_virtual(),
			'purchasable' => $product->is_purchasable() && $product->is_in_stock(),
			'price'       => (string) $product->get_price(),
		);
	}

	/**
	 * Виртуальные товары для выбора в настройках.
	 *
	 * @return list<array{id: int, name: string, virtual: bool, purchasable: bool, price: string}>
	 */
	public function virtualProducts( int $limit = 50 ): array {
		if ( ! $this->isActive() ) {
			return array();
		}

		$list = array();
		foreach ( wc_get_products( array( 'virtual' => true, 'limit' => $limit, 'status' => 'publish' ) ) as $product ) {
			$info = $this->product( $product->get_id() );
			if ( null !== $info ) {
				$list[] = $info;
			}
		}

		return $list;
	}

	/** Цена в разметке магазина (`wc_price()`); пусто — WooCommerce не активен. */
	public function formatPrice( string $price ): string {
		return $this->isActive() ? (string) wc_price( (float) $price ) : '';
	}

	// ── Корзина ────────────────────────────────────────────────────────────────────────────────────

	/** У гостя корзина живёт в сессии WooCommerce; без куки сессии позиция потерялась бы при переходе на страницу корзины. */
	public function ensureCartSession(): void {
		if ( ! $this->isActive() || ! function_exists( 'WC' ) ) {
			return;
		}

		// Сторонний обработчик сессии может не предоставлять методы штатного WC_Session_Handler.
		$session = WC()->session ?? null;
		if ( null !== $session && method_exists( $session, 'has_session' ) && method_exists( $session, 'set_customer_session_cookie' ) && ! $session->has_session() ) {
			$session->set_customer_session_cookie( true );
		}
	}

	/**
	 * Позиции корзины в нейтральном виде.
	 *
	 * @return list<array{key: string, product_id: int, data: array<string, mixed>}>
	 */
	public function cartItems(): array {
		if ( ! $this->isActive() || ! function_exists( 'WC' ) || null === WC()->cart ) {
			return array();
		}

		$items = array();
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			$items[] = array( 'key' => (string) $key, 'product_id' => (int) ( $item['product_id'] ?? 0 ), 'data' => (array) $item );
		}

		return $items;
	}

	/**
	 * Кладёт товар в корзину; `null` — WooCommerce отказал (товар недоступен, валидация не пройдена).
	 *
	 * @param array<string, mixed> $itemData Данные позиции (привязка к заявке и подпись).
	 */
	public function addToCart( int $productId, array $itemData ): ?string {
		$this->ensureCartSession();
		if ( ! $this->isActive() || ! function_exists( 'WC' ) || null === WC()->cart ) {
			return null;
		}

		$key = WC()->cart->add_to_cart( $productId, 1, 0, array(), $itemData );

		return false === $key ? null : (string) $key;
	}

	public function removeCartItem( string $key ): void {
		if ( $this->isActive() && function_exists( 'WC' ) && null !== WC()->cart ) {
			WC()->cart->remove_cart_item( $key );
		}
	}

	public function cartUrl(): string {
		return $this->isActive() ? (string) wc_get_cart_url() : '';
	}

	/** Сообщение покупателю в стиле магазина (`wc_add_notice`). */
	public function notice( string $message, string $type = 'error' ): void {
		if ( $this->isActive() && function_exists( 'wc_add_notice' ) ) {
			// Уведомления магазина живут в сессии: у нового посетителя её нужно завести, иначе сообщение пропадёт.
			$this->ensureCartSession();
			wc_add_notice( $message, $type );
		}
	}

	// ── Заказы (только CRUD WooCommerce, HPOS) ────────────────────────────────────────────────────

	/**
	 * Записывает метаданные позиции через Woo CRUD; сохранение завершает checkout.
	 *
	 * @param \WC_Order_Item $item  Позиция создаваемого заказа.
	 * @param string         $key   Ключ метаданных.
	 * @param int|string     $value Значение метаданных.
	 * @return void
	 */
	public function setOrderItemMeta( \WC_Order_Item $item, string $key, int|string $value ): void {
		$item->add_meta_data( $key, $value, true );
	}

	/**
	 * Позиции заказа с привязкой к заявке LMS.
	 *
	 * @return list<array{item_id: int, product_id: int, application_id: int, total: string}>
	 */
	public function orderExamItems( int $orderId ): array {
		$order = $this->order( $orderId );
		if ( null === $order ) {
			return array();
		}

		$items = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$applicationId = (int) $item->get_meta( ExamOrderItemMeta::Application->value );
			if ( $applicationId > 0 ) {
				$items[] = array(
					'item_id'        => (int) $item->get_id(),
					'product_id'     => (int) $item->get_product_id(),
					'application_id' => $applicationId,
					'total'          => (string) $item->get_total(),
				);
			}
		}

		return $items;
	}

	/** Заказ оплачен: `is_paid()` («Обработка», «Выполнен», нулевой заказ по купону). Статусы `pending`, `on-hold`, `failed` оплатой не считаются. */
	public function isOrderPaid( int $orderId ): bool {
		return $this->order( $orderId )?->is_paid() ?? false;
	}

	/**
	 * Создаёт заказ с одной позицией товара без корзины и оформления (тестовое окружение): позиция получает метаданные, заказ — данные покупателя.
	 *
	 * @param array<string, int|string> $itemMeta Метаданные позиции.
	 * @param array<string, string>     $billing  Поля покупателя: `first_name`, `last_name`, `phone`.
	 *
	 * @return int|null ID заказа; null — магазин или товар недоступны.
	 */
	public function createOrderForProduct( int $productId, array $itemMeta, array $billing ): ?int {
		$product = $this->isActive() && function_exists( 'wc_create_order' ) ? wc_get_product( $productId ) : null;
		if ( ! $product instanceof \WC_Product ) {
			return null;
		}

		$order = wc_create_order( array( 'created_via' => 'fs_lms_exam_test' ) );
		if ( ! $order instanceof \WC_Order ) {
			return null;
		}

		$itemId = $order->add_product( $product, 1 );
		$item   = $itemId ? $order->get_item( $itemId ) : null;
		if ( $item instanceof \WC_Order_Item ) {
			foreach ( $itemMeta as $key => $value ) {
				$this->setOrderItemMeta( $item, (string) $key, $value );
			}
			$item->save();
		}

		$order->set_billing_first_name( (string) ( $billing['first_name'] ?? '' ) );
		$order->set_billing_last_name( (string) ( $billing['last_name'] ?? '' ) );
		$order->set_billing_phone( (string) ( $billing['phone'] ?? '' ) );
		$order->calculate_totals();
		$order->save();

		return (int) $order->get_id();
	}

	/** Оплата заказа завершена штатным `payment_complete()`: статус и хуки магазина те же, что после реальной оплаты. */
	public function completePayment( int $orderId ): void {
		$this->order( $orderId )?->payment_complete();
	}

	/** Адрес страницы «Спасибо» заказа с ключом заказа. */
	public function orderReceivedUrl( int $orderId ): string {
		return (string) ( $this->order( $orderId )?->get_checkout_order_received_url() ?? '' );
	}

	/** Очищает корзину покупателя; позиции экзамена к этому моменту уже оплачены, бронь не затрагивается. */
	public function emptyCart(): void {
		if ( $this->isActive() && function_exists( 'WC' ) && null !== WC()->cart ) {
			WC()->cart->empty_cart();
		}
	}

	/** Статус заказа без префикса `wc-`: `pending`, `processing`, `failed`, `cancelled`, `refunded` … Пусто — заказа нет. */
	public function orderStatus( int $orderId ): string {
		return (string) ( $this->order( $orderId )?->get_status() ?? '' );
	}

	/** Ключ заказа из адреса страницы «Спасибо» принадлежит этому заказу. */
	public function orderKeyValid( int $orderId, string $key ): bool {
		$order = $this->order( $orderId );

		return null !== $order && '' !== $key && $order->key_is_valid( $key );
	}

	private function order( int $orderId ): ?\WC_Order {
		if ( ! $this->isActive() || $orderId <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}
		$order = wc_get_order( $orderId );

		return $order instanceof \WC_Order ? $order : null;
	}
}
