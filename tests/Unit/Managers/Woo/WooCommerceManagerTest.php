<?php

declare( strict_types=1 );

namespace Unit\Managers\Woo;

use Inc\Enums\Exam\ExamOrderItemMeta;
use Inc\Managers\Woo\WooCommerceManager;
use Inc\Services\Exam\Payment\WooExamAdapter;
use PHPUnit\Framework\TestCase;

/** Обёртка остаётся безопасной без WooCommerce, а старый контракт ключа позиции сохраняется. */
class WooCommerceManagerTest extends TestCase {

	public function test_inactive_woo_returns_no_data(): void {
		$manager = new WooCommerceManager();

		self::assertFalse( $manager->isActive() );
		self::assertNull( $manager->product( 1 ) );
		self::assertSame( array(), $manager->virtualProducts() );
		self::assertSame( array(), $manager->cartItems() );
		self::assertSame( array(), $manager->orderExamItems( 1 ) );
		self::assertFalse( $manager->isOrderPaid( 1 ) );
	}

	public function test_order_item_key_keeps_the_persisted_value(): void {
		self::assertSame( '_fs_exam_application', ExamOrderItemMeta::Application->value );
		self::assertSame( ExamOrderItemMeta::Application->value, WooExamAdapter::ORDER_ITEM_META );
	}
}
