<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;

use Inc\DTO\Exam\ExamGuestApplicationDTO;
use Inc\DTO\Exam\ExamPaymentLinkDTO;
use Inc\Repositories\WPDBRepositories\ExamGuestApplicationRepository;
use Inc\Repositories\WPDBRepositories\ExamPaymentLinkRepository;
use Inc\Services\Exam\ExamGuestBoardService;
use Inc\Services\Exam\ExamTime;
use Inc\Services\Exam\GuestParticipantMaterializer;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExamFixtures;

/**
 * Гостевая часть доски: оплата строки и брони без записи.
 */
#[AllowMockObjectsWithoutExpectations]
class ExamGuestBoardServiceTest extends TestCase {

	use ExamFixtures;

	private ExamGuestApplicationRepository&MockObject $applications;
	private ExamPaymentLinkRepository&MockObject $links;
	private ExamGuestBoardService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->applications = $this->createMock( ExamGuestApplicationRepository::class );
		$this->links        = $this->createMock( ExamPaymentLinkRepository::class );
		$materializer       = $this->createMock( GuestParticipantMaterializer::class );
		$materializer->method( 'draftName' )->willReturn( 'Иванов Пётр' );
		$time = $this->createStub( ExamTime::class );
		$time->method( 'nowUtc' )->willReturn( '2026-03-10 07:00:00' );
		$time->method( 'toLocal' )->willReturnCallback( static fn ( string $d ): string => gmdate( 'Y-m-d H:i:s', strtotime( $d . ' UTC' ) + 10800 ) );
		$time->method( 'secondsUntil' )->willReturnCallback( static fn ( string $a, string $b ): int => (int) ( strtotime( $b . ' UTC' ) - strtotime( $a . ' UTC' ) ) );

		$this->service = new ExamGuestBoardService( $this->applications, $this->links, $materializer, $time );
	}

	private function application( array $override = array() ): ExamGuestApplicationDTO {
		return ExamGuestApplicationDTO::fromArray( array_merge( array(
			'id' => '9', 'event_id' => '3', 'session_id' => '7', 'source_id' => '14', 'identity_hash' => 'h', 'state' => 'awaiting_payment', 'is_held' => '1',
			'hold_expires_at' => '2026-03-10 07:20:00', 'request_key' => 'k', 'version' => '1', 'created_at' => '2026-03-10 07:00:00', 'updated_at' => '2026-03-10 07:00:00',
		), $override ) );
	}

	private function link( string $state ): ExamPaymentLinkDTO {
		return ExamPaymentLinkDTO::fromArray( array(
			'id' => 1, 'application_id' => 9, 'wc_order_id' => 738, 'wc_order_item_id' => 5, 'product_id' => 736, 'amount' => '1500.00', 'currency' => 'RUB',
			'payment_state' => $state, 'created_at' => '2026-03-10 06:00:00', 'updated_at' => '2026-03-10 06:00:00',
		) );
	}

	public function test_payment_is_taken_from_the_order_link(): void {
		$this->applications->method( 'findByParticipation' )->willReturn( $this->application( array( 'state' => 'confirmed' ) ) );
		$this->links->method( 'findByApplication' )->willReturn( array( $this->link( 'paid' ) ) );

		self::assertSame( array( 'state' => 'paid', 'label' => 'Оплачено' ), $this->service->paymentOf( 80 ) );
	}

	public function test_pending_payment_reads_as_awaiting_payment(): void {
		$this->applications->method( 'findByParticipation' )->willReturn( $this->application() );
		$this->links->method( 'findByApplication' )->willReturn( array( $this->link( 'pending' ) ) );

		self::assertSame( 'Ожидает оплаты', $this->service->paymentOf( 80 )['label'] );
	}

	public function test_participation_without_application_or_link_has_no_payment(): void {
		$this->applications->method( 'findByParticipation' )->willReturnOnConsecutiveCalls( null, $this->application() );
		$this->links->method( 'findByApplication' )->willReturn( array() );

		self::assertNull( $this->service->paymentOf( 80 ) );
		self::assertNull( $this->service->paymentOf( 81 ) );
	}

	public function test_holds_list_shows_place_held_until_with_pay_link_action(): void {
		$this->applications->method( 'listHeldBySession' )->willReturn( array(
			$this->application(),
			$this->application( array( 'id' => '10', 'hold_expires_at' => '2026-03-10 06:59:00' ) ), // истекла — не показываем
			$this->application( array( 'id' => '11', 'created_by_user_id' => '10' ) ),
		) );

		$holds = $this->service->holdsOf( $this->examSession() );

		self::assertCount( 2, $holds );
		self::assertSame( '10:20', $holds[0]['hold_until'] );
		self::assertSame( 1200, $holds[0]['seconds_left'] );
		self::assertFalse( $holds[0]['on_site'] );
		self::assertTrue( $holds[1]['on_site'] );
		self::assertSame( array( 'copy_pay_link' ), $holds[0]['actions'] );
		self::assertSame( 'Иванов Пётр', $holds[0]['name'] );
		foreach ( array( 'phone', 'messenger' ) as $key ) {
			self::assertArrayNotHasKey( $key, $holds[0] );
		}
	}
}
