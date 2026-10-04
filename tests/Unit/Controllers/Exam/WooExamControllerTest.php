<?php

declare( strict_types=1 );

namespace Unit\Controllers\Exam;

use Inc\Controllers\Exam\WooExamController;
use PHPUnit\Framework\TestCase;

/** Совместимость с WooCommerce HPOS: контроллер не должен падать, если WooCommerce нет. */
class WooExamControllerTest extends TestCase {

	public function test_register_does_not_fail_without_woocommerce(): void {
		( new WooExamController() )->register();

		$this->addToAssertionCount( 1 );
	}

	public function test_is_woo_active_false_without_class(): void {
		self::assertFalse( class_exists( 'WooCommerce', false ), 'Предусловие: в тестовом окружении WooCommerce нет.' );
		self::assertFalse( ( new WooExamController() )->isWooActive() );
	}

	public function test_hpos_declaration_without_woocommerce_is_a_noop(): void {
		( new WooExamController() )->declareHposCompatibility();

		$this->addToAssertionCount( 1 );
	}
}
