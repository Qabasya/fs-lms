<?php
/**
 * Связь заявки с позицией заказа WooCommerce для идемпотентного подтверждения оплаты.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\DTO\Exam;

/** Связь заявки с позицией заказа WooCommerce для идемпотентного подтверждения оплаты. */
readonly class ExamPaymentLinkDTO {

	/** Принимает типизированные поля без обращения к хранилищу. */
	public function __construct(
		public int $id,
		public int $applicationId,
		public int $wcOrderId,
		public int $wcOrderItemId,
		public int $productId,
		public string $amount,
		public string $currency,
		public string $paymentState,
		public ?string $lastReconciledAt,
		public string $createdAt,
		public string $updatedAt,
	) {}

	/** Восстанавливает типы полей из строки репозитория. */
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
