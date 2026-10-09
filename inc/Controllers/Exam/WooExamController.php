<?php declare( strict_types=1 );

namespace Inc\Controllers\Exam;

use Inc\Callbacks\Exam\WooExamCallbacks;
use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;

/**
 * Хуки WooCommerce для гостевых экзаменов. Только вешает обработчики — логика в {@see WooExamCallbacks}.
 *
 * Совместимость с HPOS объявляется всегда; хуки магазина — после `woocommerce_init` и только если WooCommerce активен
 * (плагин LMS грузится раньше WooCommerce, поэтому проверка на ранней загрузке дала бы ложное «не активен»).
 */
class WooExamController extends BaseController implements ServiceInterface {

	public function __construct(
		private readonly WooExamCallbacks $callbacks,
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

		add_filter( 'woocommerce_add_to_cart_validation', array( $this->callbacks, 'validateAddToCart' ), 10, 6 );
		add_filter( 'woocommerce_cart_item_quantity', array( $this->callbacks, 'fixQuantity' ), 10, 3 );
		add_filter( 'woocommerce_update_cart_validation', array( $this->callbacks, 'validateQuantityUpdate' ), 10, 4 );
		add_filter( 'woocommerce_get_item_data', array( $this->callbacks, 'itemData' ), 10, 2 );
		add_action( 'woocommerce_cart_item_removed', array( $this->callbacks, 'onItemRemoved' ), 10, 2 );
		add_action( 'woocommerce_check_cart_items', array( $this->callbacks, 'checkCart' ) );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this->callbacks, 'copyToOrderItem' ), 10, 4 );
		add_action( 'woocommerce_checkout_order_processed', array( $this->callbacks, 'onOrderProcessed' ), 20, 1 );
		add_action( 'woocommerce_payment_complete', array( $this->callbacks, 'onOrderChanged' ), 20, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this->callbacks, 'onOrderChanged' ), 20, 1 );
		add_action( 'woocommerce_before_cart', array( $this->callbacks, 'renderHoldCountdown' ) );
		add_action( 'woocommerce_before_checkout_form', array( $this->callbacks, 'renderHoldCountdown' ) );
		add_action( 'woocommerce_thankyou', array( $this->callbacks, 'renderThankYou' ), 20, 1 );
	}
}
