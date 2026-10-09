<?php

declare( strict_types=1 );

namespace Inc\Repositories\OptionsRepositories;

use Inc\Enums\Settings\OptionName;

readonly class PluginConfigRepository {

	private const DEFAULTS = array(
		'test_env'        => false,
		'otp_bypass_code' => '',
		'brand_logo_id'   => 0, // attachment ID логотипа кабинета (0 — дефолтный BrandMark)
		// Куда ведёт «Записаться» в блоке ответа у заданий с ручной проверкой
		// (13–16 ОГЭ и т.п.): у них правильного ответа нет, вместо него —
		// приглашение на консультацию. Пусто — приглашение не показывается.
		'consultation_url' => '',
		// Экзамены для гостей (этап 11a.6): товары WooCommerce по классу, срок брони, лимиты, хранение, запасные контакты центра.
		'exam_product_9'           => 0,
		'exam_product_11'          => 0,
		'exam_hold_minutes'        => 20,
		'exam_ip_active_holds'     => 40,
		'exam_ip_hourly'           => 60,
		'exam_source_active_holds' => 60,
		'exam_guest_retention_days' => 365,
		'exam_unpaid_retention_days' => 30,
		'center_phone'             => '',
		'center_email'             => '',
		'center_hours'             => '',
		'center_address'           => '',
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
