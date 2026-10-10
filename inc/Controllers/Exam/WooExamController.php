<?php declare( strict_types=1 );

namespace Inc\Controllers\Exam;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Services\Exam\Payment\ExamPaymentReconciler;
use Inc\Services\Exam\Payment\GuestOrderStatusService;
use Inc\Services\Exam\Payment\WooExamAdapter;
use Inc\Managers\Woo\WooCommerceManager;
use Inc\Shared\PluginLogger;
use Inc\Shared\Traits\TemplateRenderer;

use Inc\Shared\Traits\Sanitizer;

/**
 * Хуки WooCommerce для гостевых экзаменов. Адаптирует хуки; решения — в WooExamAdapter/ExamPaymentReconciler, Woo API — в менеджере.
 *
 * Совместимость с HPOS объявляется всегда; хуки магазина — после `woocommerce_init` и только если WooCommerce активен
 * (плагин LMS грузится раньше WooCommerce, поэтому проверка на ранней загрузке дала бы ложное «не активен»).
 */
class WooExamController extends BaseController implements ServiceInterface {

	use TemplateRenderer;
	use Sanitizer;

	public function __construct(
		private readonly WooExamAdapter $adapter,
		private readonly ExamPaymentReconciler $reconciler,
		private readonly GuestOrderStatusService $orderStatus,
		private readonly WooCommerceManager $woo,
	) {
		parent::__construct();
	}

	public function register(): void {
		add_action( 'before_woocommerce_init', array( $this, 'declareHposCompatibility' ) );
		add_action( 'woocommerce_init', array( $this, 'registerShopHooks' ) );
	}

	public function declareHposCompatibility(): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', FS_LMS_PLUGIN_FILE, true );
		}
	}

	public function isWooActive(): bool {
		return class_exists( 'WooCommerce' );
	}

	public function registerShopHooks(): void {
		if ( ! $this->isWooActive() ) {
			return;
		}

		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validateAddToCart' ), 10, 6 );
		add_filter( 'woocommerce_cart_item_quantity', array( $this, 'fixQuantity' ), 10, 3 );
		add_filter( 'woocommerce_update_cart_validation', array( $this, 'validateQuantityUpdate' ), 10, 4 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'itemData' ), 10, 2 );
		add_action( 'woocommerce_cart_item_removed', array( $this, 'onItemRemoved' ), 10, 2 );
		add_action( 'woocommerce_check_cart_items', array( $this, 'checkCart' ) );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'copyToOrderItem' ), 10, 4 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'onOrderProcessed' ), 20, 1 );
		add_action( 'woocommerce_payment_complete', array( $this, 'onOrderChanged' ), 20, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'onOrderChanged' ), 20, 1 );
		add_action( 'woocommerce_before_cart', array( $this, 'renderHoldCountdown' ) );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'renderHoldCountdown' ) );
		add_action( 'woocommerce_thankyou', array( $this, 'renderThankYou' ), 20, 1 );
	}
	/**
	 * Товар экзамена без данных заявки в корзину не попадает: прямая ссылка «в корзину» место не бронирует.
	 *
	 * @param mixed                $passed
	 * @param array<string, mixed> $cartItemData
	 */
	public function validateAddToCart( mixed $passed, mixed $productId = 0, mixed $quantity = 1, mixed $variationId = 0, mixed $variations = array(), mixed $cartItemData = array() ): mixed {
		if ( ! $this->adapter->isAddAllowed( (int) $productId, (array) $cartItemData ) ) {
			$this->woo->notice( 'Сначала выберите дату: откройте ссылку-приглашение.' );

			return false;
		}

		return $passed;
	}

	/** Количество экзаменной позиции всегда 1 и не редактируется. @param mixed $html @param array<string, mixed> $item */
	public function fixQuantity( mixed $html, mixed $cartItemKey = '', mixed $item = array() ): string {
		return $this->adapter->quantityHtml( (string) $html, (array) $item );
	}

	/** @param mixed $passed @param array<string, mixed> $values */
	public function validateQuantityUpdate( mixed $passed, mixed $cartItemKey = '', mixed $values = array(), mixed $quantity = 1 ): bool {
		return $this->adapter->isQuantityUpdateAllowed( (bool) $passed, (array) $values, (int) $quantity );
	}

	/**
	 * Участник, дата и время, срок брони под названием товара.
	 *
	 * @param array<int, array<string, string>> $itemData
	 * @param array<string, mixed>              $cartItem
	 *
	 * @return array<int, array<string, string>>
	 */
	public function itemData( array $itemData, mixed $cartItem = array() ): array {
		foreach ( $this->adapter->itemDisplay( (array) $cartItem ) as $row ) {
			$itemData[] = $row;
		}

		return $itemData;
	}

	/** Позицию удалили — бронь снимается. @param string $cartItemKey @param \WC_Cart $cart */
	public function onItemRemoved( mixed $cartItemKey, mixed $cart ): void {
		$removed = $this->woo->removedCartItem( $cart, (string) $cartItemKey );
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
	public function copyToOrderItem( mixed $item, mixed $cartItemKey = '', mixed $values = array(), mixed $order = null ): void {
		if ( null !== $this->adapter->applicationOfItem( (array) $values ) ) {
			$this->woo->setOrderItemMeta( $item, WooExamAdapter::ORDER_ITEM_META, (int) $values[ WooExamAdapter::ITEM_APPLICATION ] );
		}
	}

	/** Заказ сохранён: связи с заявками и этап «ожидается подтверждение оплаты». */
	public function onOrderProcessed( mixed $orderId ): void {
		$this->guarded( (int) $orderId, fn () => $this->adapter->linkOrder( (int) $orderId ) );
	}

	/** `woocommerce_payment_complete` и `woocommerce_order_status_changed`: могут прийти дважды и в любом порядке — сверка идемпотентна. */
	public function onOrderChanged( mixed $orderId ): void {
		$this->guarded( (int) $orderId, fn () => $this->reconciler->reconcileOrder( (int) $orderId ) );
	}

	/** Обратный отсчёт брони в корзине и на оформлении; время — с сервера, клиент только тикает. */
	public function renderHoldCountdown(): void {
		foreach ( $this->adapter->holdCountdowns() as $data ) {
			$this->render( 'frontend/exam-hold-countdown', $data );
		}
	}

	/** Блок статуса на странице «Спасибо» — только владельцу заказа (ключ заказа из адреса). */
	public function renderThankYou( mixed $orderId ): void {
		$key    = $this->sanitizeGetText( 'key' );
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
