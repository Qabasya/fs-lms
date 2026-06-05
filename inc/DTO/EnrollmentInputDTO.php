<?php

declare( strict_types=1 );

namespace Inc\DTO;

readonly class EnrollmentInputDTO {

	public function __construct(
		public int    $applicationId,
		public string $contractNo,
		public string $contractDate,
		public string $orderNo,
		public string $orderDate,
		public string $enrolledAt,
		public string $groupKey,
		public bool   $sendEmailAuto,
	) {}
}
