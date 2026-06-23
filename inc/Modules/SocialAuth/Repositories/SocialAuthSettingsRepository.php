<?php

declare( strict_types=1 );

namespace Inc\Modules\SocialAuth\Repositories;

use Inc\Enums\Settings\OptionName;

class SocialAuthSettingsRepository {

	private string $option_name = OptionName::AuthSettings->value;

	private static ?array $cache = null;

	public function readAll(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$data        = get_option( $this->option_name, array() );
		self::$cache = is_array( $data ) ? $data : array();

		return self::$cache;
	}

	public function update( array $data ): bool {
		$result = update_option( $this->option_name, $data, 'no' );

		if ( $result ) {
			self::$cache = $data;
		}

		return false !== $result;
	}

	public function delete(): bool {
		$result = delete_option( $this->option_name );

		if ( $result ) {
			self::$cache = null;
		}

		return $result;
	}

	public function isProviderEnabled( string $provider_key ): bool {
		$settings    = $this->readAll();
		$setting_key = strtolower( $provider_key ) . '_enabled';

		return ! empty( $settings[ $setting_key ] );
	}

	public function getProviderKeys( string $provider_key ): array {
		$settings = $this->readAll();
		$prefix   = strtolower( $provider_key );

		return array(
			'id'     => $settings[ $prefix . '_id' ] ?? '',
			'secret' => $settings[ $prefix . '_secret' ] ?? '',
		);
	}

	public function clearCache(): void {
		self::$cache = null;
	}
}
