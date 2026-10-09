<?php

declare( strict_types=1 );

namespace Unit\Controllers\Exam;

use Inc\Callbacks\Exam\WooExamCallbacks;
use Inc\Controllers\Exam\WooExamController;
use PHPUnit\Framework\TestCase;

/** Совместимость с WooCommerce HPOS: контроллер не должен падать, если WooCommerce нет. */
class WooExamControllerTest extends TestCase {

	public function test_register_does_not_fail_without_woocommerce(): void {
		( $this->controller() )->register();

		$this->addToAssertionCount( 1 );
	}

	public function test_is_woo_active_false_without_class(): void {
		self::assertFalse( class_exists( 'WooCommerce', false ), 'Предусловие: в тестовом окружении WooCommerce нет.' );
		self::assertFalse( ( $this->controller() )->isWooActive() );
	}

	public function test_hpos_declaration_without_woocommerce_is_a_noop(): void {
		( $this->controller() )->declareHposCompatibility();

		$this->addToAssertionCount( 1 );
	}

	public function test_shop_hooks_are_not_registered_without_woocommerce(): void {
		$GLOBALS['_fs_test_actions'] = array();

		$this->controller()->registerShopHooks();

		self::assertEmpty( $GLOBALS['_fs_test_actions'] ?? array() );
	}

	private function controller(): WooExamController {
		return new WooExamController( $this->createMock( WooExamCallbacks::class ) );
	}
}
