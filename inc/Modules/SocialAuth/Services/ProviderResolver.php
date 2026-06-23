<?php

declare( strict_types=1 );

namespace Inc\Modules\SocialAuth\Services;

use Inc\Modules\SocialAuth\Enums\AuthProvider;
use Inc\Shared\Traits\Sanitizer;

class ProviderResolver {

	use Sanitizer;

	public function fromRequest(): ?AuthProvider {
		$provider = $this->sanitizeText( 'provider', 'GET' );

		if ( '' === $provider ) {
			return null;
		}

		return AuthProvider::fromRequest( $provider );
	}

	public function fromCallback(): ?AuthProvider {
		return $this->fromRequest();
	}
}
