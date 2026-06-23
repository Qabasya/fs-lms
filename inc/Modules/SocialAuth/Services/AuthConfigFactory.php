<?php

declare( strict_types=1 );

namespace Inc\Modules\SocialAuth\Services;

use Inc\Modules\SocialAuth\Enums\AuthProvider;
use Inc\Modules\SocialAuth\Repositories\SocialAuthSettingsRepository;

readonly class AuthConfigFactory {

	public function __construct(
		private SocialAuthSettingsRepository $settings_repo
	) {}

	public function make( ?AuthProvider $provider = null ): array {
		return array(
			'callback'   => $this->buildCallback( $provider ),
			'debug_mode' => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'debug_file' => WP_CONTENT_DIR . '/hybridauth.log',
			'providers'  => $this->buildProvidersConfig(),
		);
	}

	private function buildCallback( ?AuthProvider $provider ): string {
		$base = home_url( '/lms-auth/callback' );

		if ( ! $provider ) {
			return $base;
		}

		return add_query_arg( 'provider', strtolower( $provider->value ), $base );
	}

	private function buildProvidersConfig(): array {
		$settings = $this->settings_repo->readAll();
		$result   = array();

		foreach ( AuthProvider::cases() as $provider ) {
			$key                              = $provider->configKey();
			$result[ $provider->hybridauthKey() ] = array(
				'enabled' => ! empty( $settings[ $key . '_enabled' ] ),
				'keys'    => array(
					'id'     => $settings[ $key . '_id' ] ?? '',
					'secret' => $settings[ $key . '_secret' ] ?? '',
				),
			);
		}

		return $result;
	}
}
