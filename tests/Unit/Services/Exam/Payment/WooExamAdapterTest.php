<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam\Payment;

use Inc\DTO\Exam\ExamGuestApplicationDTO;
use Inc\DTO\Exam\ExamPaymentLinkDTO;
use Inc\Enums\Exam\GuestApplicationState;
use Inc\Enums\Log\ErrorCode;
use Inc\Repositories\WPDBRepositories\ExamGuestApplicationRepository;
use Inc\Repositories\WPDBRepositories\ExamPaymentLinkRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Services\Exam\ExamHoldService;
use Inc\Services\Exam\ExamTime;
use Inc\Services\Exam\GuestParticipantMaterializer;
use Inc\Services\Exam\Payment\WooExamAdapter;
use Inc\Services\Exam\Payment\WooGateway;
use Inc\Services\Shared\PluginConfig;
use Inc\Shared\CodedException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Адаптер корзины: товар экзамена без заявки не добавляется, подпись привязки, одна заявка на корзину, снятие брони, связи заказа.
 * Функции WooCommerce — только через мок {@see WooGateway}.
 */
#[AllowMockObjectsWithoutExpectations]
class WooExamAdapterTest extends TestCase {

	private WooGateway&MockObject $woo;
	private ExamGuestApplicationRepository&MockObject $applications;
	private ExamPaymentLinkRepository&MockObject $links;
	private ExamHoldService&MockObject $holds;
	private WooExamAdapter $adapter;

	/** @var list<array{key: string, product_id: int, data: array<string, mixed>}> */
	private array $cart = array();
	/** @var array<string, mixed> */
	private array $added = array();
	private string $nowUtc = '2026-03-10 07:00:00';

	protected function setUp(): void {
		parent::setUp();

		$this->woo          = $this->createMock( WooGateway::class );
		$this->applications = $this->createMock( ExamGuestApplicationRepository::class );
		$this->links        = $this->createMock( ExamPaymentLinkRepository::class );
		$this->holds        = $this->createMock( ExamHoldService::class );
		$config             = $this->createMock( PluginConfig::class );
		$config->method( 'examProductId' )->willReturn( 500 );
		$time = $this->createStub( ExamTime::class );
		$time->method( 'nowUtc' )->willReturnCallback( fn (): string => $this->nowUtc );
		$time->method( 'toLocal' )->willReturnArgument( 0 );

		$this->woo->method( 'product' )->willReturn( array( 'id' => 500, 'name' => 'Экзамен', 'virtual' => true, 'purchasable' => true, 'price' => '500' ) );
		$this->woo->method( 'cartItems' )->willReturnCallback( fn (): array => $this->cart );
		$this->woo->method( 'addToCart' )->willReturnCallback( function ( int $productId, array $data ): string {
			$this->added = $data;
			return 'cartkey1';
		} );
		$this->applications->method( 'find' )->willReturnCallback( fn ( int $id ): ?ExamGuestApplicationDTO => 9 === $id ? $this->app() : ( 10 === $id ? $this->app( array( 'id' => '10', 'request_key' => 'k2' ) ) : null ) );

		$this->adapter = new WooExamAdapter(
			$this->woo, $config, $this->applications, $this->links, $this->createMock( ExamSessionRepository::class ), $this->holds,
			$this->createMock( GuestParticipantMaterializer::class ), $time
		);
	}

	/** @param array<string, mixed> $override */
	private function app( array $override = array() ): ExamGuestApplicationDTO {
		return ExamGuestApplicationDTO::fromArray( array_merge( array(
			'id' => '9', 'event_id' => '3', 'session_id' => '7', 'source_id' => '14', 'identity_hash' => 'h', 'state' => 'hold', 'is_held' => '1',
			'hold_expires_at' => '2026-03-10 07:20:00', 'request_key' => 'k1', 'source_snapshot' => '{"grade":11}', 'version' => '1',
			'created_at' => '2026-03-10 06:55:00', 'updated_at' => '2026-03-10 06:55:00',
		), $override ) );
	}

	/** @return array<string, mixed> */
	private function item( int $id = 9, ?string $signature = null ): array {
		return array( WooExamAdapter::ITEM_APPLICATION => $id, WooExamAdapter::ITEM_SIGNATURE => $signature ?? $this->adapter->signature( 9 === $id ? $this->app() : $this->app( array( 'id' => '10', 'request_key' => 'k2' ) ) ) );
	}

	public function test_exam_product_without_application_cannot_be_added(): void {
		self::assertFalse( $this->adapter->isAddAllowed( 500, array() ) );
		self::assertFalse( $this->adapter->isAddAllowed( 500, array( WooExamAdapter::ITEM_APPLICATION => 9 ) ), 'Без подписи — отказ.' );
		self::assertTrue( $this->adapter->isAddAllowed( 500, $this->item() ) );
		self::assertTrue( $this->adapter->isAddAllowed( 77, array() ), 'Чужие товары магазина не затрагиваются.' );
	}

	public function test_item_data_signature_mismatch_is_rejected(): void {
		self::assertNull( $this->adapter->applicationOfItem( $this->item( 9, str_repeat( 'a', 64 ) ) ) );
		// Подпись заявки 10, ID подменён на 9.
		self::assertNull( $this->adapter->applicationOfItem( array( WooExamAdapter::ITEM_APPLICATION => 9, WooExamAdapter::ITEM_SIGNATURE => $this->item( 10 )[ WooExamAdapter::ITEM_SIGNATURE ] ) ) );
	}

	public function test_add_to_cart_adds_signed_item_and_advances_state(): void {
		$this->holds->expects( self::once() )->method( 'transition' )->with( 9, array( GuestApplicationState::Hold ), GuestApplicationState::AwaitingPayment );

		$this->adapter->addToCart( $this->app() );

		self::assertSame( 9, $this->added[ WooExamAdapter::ITEM_APPLICATION ] );
		self::assertSame( $this->adapter->signature( $this->app() ), $this->added[ WooExamAdapter::ITEM_SIGNATURE ] );
	}

	public function test_repeat_for_same_application_adds_no_second_item(): void {
		$this->cart = array( array( 'key' => 'k', 'product_id' => 500, 'data' => $this->item() ) );
		$this->woo->expects( self::never() )->method( 'addToCart' );

		$this->adapter->addToCart( $this->app() );
	}

	public function test_second_application_in_same_cart_is_rejected_and_foreign_items_kept(): void {
		$this->cart = array(
			array( 'key' => 'foreign', 'product_id' => 77, 'data' => array() ),
			array( 'key' => 'exam', 'product_id' => 500, 'data' => $this->item( 10 ) ),
		);
		$this->woo->expects( self::never() )->method( 'removeCartItem' );

		try {
			$this->adapter->addToCart( $this->app() );
			self::fail( 'Ожидался отказ.' );
		} catch ( CodedException $e ) {
			self::assertSame( ErrorCode::ExamConflict, $e->errorCode );
			self::assertSame( 'Завершите оформление первой записи, затем запишите второго участника.', $e->getMessage() );
		}
	}

	public function test_unavailable_product_is_rejected(): void {
		$woo = $this->createMock( WooGateway::class );
		$woo->method( 'product' )->willReturn( null );
		$config = $this->createMock( PluginConfig::class );
		$config->method( 'examProductId' )->willReturn( 500 );
		$adapter = new WooExamAdapter( $woo, $config, $this->applications, $this->links, $this->createMock( ExamSessionRepository::class ), $this->holds, $this->createMock( GuestParticipantMaterializer::class ), $this->createStub( ExamTime::class ) );

		$this->expectException( CodedException::class );
		$adapter->addToCart( $this->app() );
	}

	public function test_removed_item_releases_hold(): void {
		$this->holds->expects( self::once() )->method( 'release' )->with( 9, GuestApplicationState::Cancelled );

		$this->adapter->onItemRemoved( $this->item() );
	}

	public function test_removed_item_with_bad_signature_releases_nothing(): void {
		$this->holds->expects( self::never() )->method( 'release' );

		$this->adapter->onItemRemoved( $this->item( 9, 'bad' ) );
	}

	public function test_expired_application_item_is_removed_from_cart(): void {
		$this->nowUtc = '2026-03-10 07:30:00'; // бронь до 07:20 истекла
		$this->cart   = array(
			array( 'key' => 'exam', 'product_id' => 500, 'data' => $this->item() ),
			array( 'key' => 'foreign', 'product_id' => 77, 'data' => array() ),
		);
		$this->woo->expects( self::once() )->method( 'removeCartItem' )->with( 'exam' );

		self::assertTrue( $this->adapter->cleanCart() );
	}

	public function test_live_hold_stays_in_cart(): void {
		$this->cart = array( array( 'key' => 'exam', 'product_id' => 500, 'data' => $this->item() ) );
		$this->woo->expects( self::never() )->method( 'removeCartItem' );

		self::assertFalse( $this->adapter->cleanCart() );
	}

	// ── Связи заказа ──────────────────────────────────────────────────────────────────────────────

	public function test_link_snapshot_keeps_amount_and_product(): void {
		$this->woo->method( 'orderExamItems' )->willReturn( array( array( 'item_id' => 31, 'product_id' => 500, 'application_id' => 9, 'total' => '0.00' ) ) );
		$this->links->method( 'findByWcOrderItem' )->willReturn( null );
		$inserted = null;
		$this->links->method( 'insert' )->willReturnCallback( static function ( array $row ) use ( &$inserted ): int {
			$inserted = $row;
			return 1;
		} );
		$this->holds->expects( self::once() )->method( 'transition' )->with( 9, array( GuestApplicationState::Hold, GuestApplicationState::AwaitingPayment ), GuestApplicationState::PaymentPending );

		$this->adapter->linkOrder( 77 );

		self::assertSame( '0.00', $inserted['amount'], 'Нулевая сумма по купону — допустима.' );
		self::assertSame( 500, $inserted['product_id'] );
		self::assertSame( 31, $inserted['wc_order_item_id'] );
		self::assertSame( 'pending', $inserted['payment_state'] );
	}

	public function test_repeat_link_call_creates_no_duplicate(): void {
		$this->woo->method( 'orderExamItems' )->willReturn( array( array( 'item_id' => 31, 'product_id' => 500, 'application_id' => 9, 'total' => '500' ) ) );
		$this->links->method( 'findByWcOrderItem' )->willReturn( ExamPaymentLinkDTO::fromArray( array(
			'id' => 1, 'application_id' => 9, 'wc_order_id' => 77, 'wc_order_item_id' => 31, 'product_id' => 500, 'amount' => '500', 'currency' => 'RUB',
			'payment_state' => 'pending', 'created_at' => '2026-03-10 07:00:00', 'updated_at' => '2026-03-10 07:00:00',
		) ) );
		$this->links->expects( self::never() )->method( 'insert' );

		$this->adapter->linkOrder( 77 );
	}
}
