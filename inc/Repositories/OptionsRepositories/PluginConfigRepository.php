<?php

declare( strict_types=1 );

namespace Inc\Repositories\OptionsRepositories;

use Inc\Enums\OptionName;

readonly class PluginConfigRepository {

	private const DEFAULTS = array(
		'dadata_token'                 => '',
		'test_env'                     => false,
		'otp_bypass_code'              => '',
		'captcha_site_key'             => '',
		'captcha_server_key'           => '',
		// Привязка заявки к направлению (Этап 0 / WpToADTasks).
		'applications_bind_to_subject' => false,
		'direction_codes'              => array(), // [ subject_key => code ]
	);

	public function get(): array {
		$stored = get_option( OptionName::PluginConfig->value, array() );
		return array_merge( self::DEFAULTS, is_array( $stored ) ? $stored : array() );
	}

	/** Мержит $partial поверх текущего значения; неизвестные ключи игнорирует. */
	public function save( array $partial ): void {
		$current = $this->get();
		$updated = array_merge( $current, array_intersect_key( $partial, self::DEFAULTS ) );
		update_option( OptionName::PluginConfig->value, $updated, false );
	}
}
