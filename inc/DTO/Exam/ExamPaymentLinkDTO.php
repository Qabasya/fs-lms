<?php

declare( strict_types=1 );

namespace Inc\DTO\Exam;

readonly class ExamPaymentLinkDTO {

	public function __construct(
		public int     $id,
		public int     $applicationId,
		public int     $wcOrderId,
		public int     $wcOrderItemId,
		public int     $productId,
		public string  $amount,
		public string  $currency,
		public string  $paymentState,
		public ?string $lastReconciledAt,
		public string  $createdAt,
		public string  $updatedAt,
	) {}

	public static function fromArray( array $row ): self {
		return new self(
			id                  : (int) $row['id'],
			applicationId       : (int) $row['application_id'],
			wcOrderId           : (int) $row['wc_order_id'],
			wcOrderItemId       : (int) $row['wc_order_item_id'],
			productId           : (int) $row['product_id'],
			amount              : (string) $row['amount'],
			currency            : (string) $row['currency'],
			paymentState        : (string) $row['payment_state'],
			lastReconciledAt    : $row['last_reconciled_at'] ?? null,
			createdAt           : (string) $row['created_at'],
			updatedAt           : (string) $row['updated_at'],
		);
	}
}
