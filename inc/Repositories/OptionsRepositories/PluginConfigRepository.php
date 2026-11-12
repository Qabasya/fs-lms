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
		// Дата и время экзамена по предмету (`ключ предмета => ГГГГ-ММ-ДД ЧЧ:ММ`, местное время): плитка «До экзамена» у ученика.
		'exam_dates'               => array(),
		'ranking_practice'                 => 0.0,
		'ranking_independent'              => 1.0,
		'ranking_homework'                 => 1.0,
		'ranking_survey'                   => 1.0,
		'ranking_assessment'               => 1.0,
		'ranking_attendance_bonus'         => 0.0,
		'ranking_attendance_streak_length' => 3,
		'ranking_hidden_subjects'           => array(),
		'ranking_excluded_students'          => array(),
		'ranking_subjects_version'           => 0,
		'ranking_subjects'                   => array(),
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
		$this->mutate( static fn( array $current ): array => array_merge( $current, array_intersect_key( $partial, self::DEFAULTS ) ) );
	}

	/** Serialize all configuration and subject lifecycle writers. */
	public function mutate( callable $operation, ?\wpdb $wpdb = null ): void {
		$wpdb = $wpdb ?? $GLOBALS['wpdb'];
		$database = $wpdb->get_var( 'SELECT DATABASE()' );
		if ( ! is_string( $database ) || '' === $database ) {
			throw new \RuntimeException( 'Не удалось определить базу для блокировки настроек.' );
		}
		$key = 'fs-lms:config:' . substr( hash( 'sha256', $database . ':' . $wpdb->options ), 0, 40 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $key ) ) ) {
			throw new \RuntimeException( 'Настройки заняты другой операцией. Повторите попытку.' );
		}
		try {
			wp_cache_delete( OptionName::PluginConfig->value, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			$current = $this->get();
			$updated = $operation( $current );
			$this->writeLocked( $updated );
		} finally {
			if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) ) ) {
				throw new \RuntimeException( 'Не удалось освободить блокировку настроек.' );
			}
		}
	}

	/** Write while mutate() holds the lifecycle lock; safe to call before creating a subject. */
	public function writeLocked( array $updated ): void {
		if ( $updated !== $this->get() && ! update_option( OptionName::PluginConfig->value, $updated, false ) ) {
			throw new \RuntimeException( 'Не удалось сохранить настройки.' );
		}
	}

	/** Merge a subject patch without replacing its other fields or neighbouring subjects. */
	public function saveRankingSubject( string $subject, array $patch ): void {
		$this->mutate( static function ( array $config ) use ( $subject, $patch ): array {
			$map = is_array( $config['ranking_subjects'] ) ? $config['ranking_subjects'] : array();
			$current = isset( $map[ $subject ] ) && is_array( $map[ $subject ] ) ? $map[ $subject ] : array();
			$map[ $subject ] = array_merge( $current, $patch );
			$config['ranking_subjects'] = $map;
			return $config;
		} );
	}

	/** Forget a deleted subject so reusing its key starts with defaults. */
	public function removeRankingSubject( string $subject ): void {
		$this->mutate( static function ( array $config ) use ( $subject ): array {
			if ( is_array( $config['ranking_subjects'] ) ) {
				unset( $config['ranking_subjects'][ $subject ] );
			}
			return $config;
		} );
	}
}
