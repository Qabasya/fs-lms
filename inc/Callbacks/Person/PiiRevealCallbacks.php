<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Person;

use Inc\Core\BaseController;
use Inc\Enums\Capability;
use Inc\Enums\Nonce;
use Inc\Services\Person\PersonReader;
use Inc\Services\RateLimitService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

class PiiRevealCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly PersonReader     $personReader,
		private readonly RateLimitService $rateLimitService,
	) {
		parent::__construct();
	}

	public function ajaxRevealPiiField(): void {
		$this->authorize( Nonce::RevealPii, Capability::ViewPII );

		if ( ! $this->rateLimitService->allowPiiReveal( get_current_user_id() ) ) {
			$this->error( 'Лимит раскрытий превышен.', 429 );
		}

		$personId = $this->sanitizeInt( 'person_id' );
		$field    = $this->sanitizeText( 'field' );
		$reason   = $this->sanitizeText( 'reason' );

		try {
			$value = $this->personReader->readField( $personId, $field, $reason );
			$this->success( array( 'value' => $value ) );
		} catch ( \RuntimeException $e ) {
			$this->error( $e->getMessage() );
		}
	}

	public function ajaxRevealAllPersonPii(): void {
		$this->authorize( Nonce::RevealPii, Capability::ViewPII );

		if ( ! $this->rateLimitService->allowPiiReveal( get_current_user_id() ) ) {
			$this->error( 'Лимит раскрытий превышен.', 429 );
		}

		$personId = $this->sanitizeInt( 'person_id' );
		$reason   = $this->sanitizeText( 'reason' ) ?: 'admin_full_reveal';

		try {
			$dto = $this->personReader->readForDisplay(
				$personId,
				array( 'doc_number', 'inn', 'address', 'phone' ),
				$reason
			);

			$payload = array(
				'doc_number' => $dto->pass,
				'inn'        => $dto->inn,
				'address'    => $dto->address,
				'phone'      => $dto->phone,
			);

			$issuedParts = $this->personReader->readDocIssuedParts( $personId, $reason );
			$payload['doc_issued_by']   = $issuedParts['by'];
			$payload['doc_issued_date'] = $issuedParts['date'];

			$this->success( $payload );
		} catch ( \RuntimeException $e ) {
			$this->error( $e->getMessage() );
		}
	}
}
