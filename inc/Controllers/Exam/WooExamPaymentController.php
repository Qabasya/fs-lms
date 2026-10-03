<?php

declare( strict_types=1 );

namespace Inc\Controllers\Exam;

use Inc\Contracts\ServiceInterface;
use Inc\Services\Exam\ExamPaymentService;

class WooExamPaymentController implements ServiceInterface {

	private ExamPaymentService $paymentService;

	public function __construct( ?ExamPaymentService $paymentService = null ) {
		$this->paymentService = $paymentService ?? new ExamPaymentService();
	}

	public function register(): void {
		add_action( 'woocommerce_order_status_completed', [ $this, 'onOrderCompleted' ], 10, 1 );
		add_action( 'woocommerce_order_status_processing', [ $this, 'onOrderProcessing' ], 10, 1 );
	}

	public function onOrderCompleted( int $orderId ): void {
		$this->updatePaymentState( $orderId, 'completed' );
	}

	public function onOrderProcessing( int $orderId ): void {
		$this->updatePaymentState( $orderId, 'processing' );
	}

	private function updatePaymentState( int $orderId, string $state ): void {
		if ( !function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( $orderId );
		if ( !$order ) {
			return;
		}

		foreach ( $order->get_items() as $item ) {
			$paymentLinkId = $item->get_meta( '_exam_payment_link_id' );
			if ( $paymentLinkId ) {
				$this->paymentService->updatePaymentState( (int) $paymentLinkId, $state );
			}
		}
	}
}
