<?php declare( strict_types=1 );

namespace Inc\Controllers\Exam;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;

class WooExamController extends BaseController implements ServiceInterface {

	public function register(): void {
		add_action( 'before_woocommerce_init', array( $this, 'declareHposCompatibility' ) );
	}

	public function declareHposCompatibility(): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', FS_LMS_PLUGIN_FILE, true );
		}
	}

	public function isWooActive(): bool {
		return class_exists( 'WooCommerce' );
	}
}
