<?php

declare( strict_types=1 );

namespace Inc\Services\Shared;

use Inc\Repositories\OptionsRepositories\PluginConfigRepository;

/**
 * Единая точка чтения core-настроек «тестового окружения»:
 * FS_LMS_TEST_ENV, FS_LMS_OTP_BYPASS_CODE.
 *
 * Правило: константа из wp-config.php имеет приоритет над wp_options.
 * Ключи шифрования (FS_LMS_ENC_KEY / FS_LMS_HASH_SALT) читаются ТОЛЬКО
 * через defined() в PiiCryptoService. DaData-токен и ключи SmartCaptcha
 * переехали в свои модули (Inc\Modules\DaData / Inc\Modules\SmartCaptcha).
 */
readonly class PluginConfig {

	public function __construct(
		private PluginConfigRepository $repository,
	) {}

	public function isTestEnv(): bool {
		if ( defined( 'FS_LMS_TEST_ENV' ) ) {
			return true;
		}
		return (bool) ( $this->repository->get()['test_env'] ?? false );
	}

	public function otpBypassCode(): string {
		if ( defined( 'FS_LMS_OTP_BYPASS_CODE' ) ) {
			return (string) FS_LMS_OTP_BYPASS_CODE;
		}
		return (string) ( $this->repository->get()['otp_bypass_code'] ?? '' );
	}

	/**
	 * Белые IP (статический адрес сети, откуда подают заявки очно) — для них повышены IP-лимиты.
	 *
	 * Только константа `FS_LMS_TRUSTED_IPS` в wp-config.php, без опции в БД: адрес меняется
	 * редко, а правка из админки расширила бы круг тех, кто может ослабить защиту.
	 * Формат — точные адреса через запятую (или массив); маски подсетей не поддерживаются.
	 *
	 * @return string[]
	 */
	public function trustedIps(): array {
		if ( ! defined( 'FS_LMS_TRUSTED_IPS' ) ) {
			return array();
		}

		$raw  = FS_LMS_TRUSTED_IPS;
		$list = is_array( $raw ) ? $raw : explode( ',', (string) $raw );

		return array_values( array_filter(
			array_map( static fn( $ip ): string => trim( (string) $ip ), $list ),
			static fn( string $ip ): bool => false !== filter_var( $ip, FILTER_VALIDATE_IP )
		) );
	}

	/**
	 * Ссылка на форму записи на консультацию. Показывается в блоке «Показать
	 * ответ» у заданий, которые проверяет преподаватель: автоматического ответа
	 * у них нет ({@see \Inc\Enums\Subject\TaskTemplate::isFileAnswerShape()}).
	 * Пусто — блок не показывается вовсе.
	 */
	public function consultationUrl(): string {
		return (string) ( $this->repository->get()['consultation_url'] ?? '' );
	}

	/** Attachment ID логотипа кабинета; 0 — не задан. */
	public function brandLogoId(): int {
		return (int) ( $this->repository->get()['brand_logo_id'] ?? 0 );
	}

	/** URL логотипа кабинета; '' — не задан (фронт показывает дефолтный BrandMark). */
	public function brandLogoUrl(): string {
		$id = $this->brandLogoId();
		if ( $id <= 0 ) {
			return '';
		}

		$url = wp_get_attachment_image_url( $id, 'medium' );

		return $url ?: '';
	}

	/** Товар WooCommerce для экзамена класса (9 или 11); 0 — не задан. Один товар может обслуживать оба класса. */
	public function examProductId( int $grade ): int {
		$key = 9 === $grade ? 'exam_product_9' : 'exam_product_11';

		return max( 0, (int) ( $this->repository->get()[ $key ] ?? 0 ) );
	}

	/** Срок временной брони, мин: 5…120, по умолчанию 20. */
	public function examHoldMinutes(): int {
		return $this->bounded( 'exam_hold_minutes', 20, 5, 120 );
	}

	/** Одновременно активных броней с одного IP (по умолчанию 40: за общим адресом школы ~20 человек). */
	public function examIpActiveHoldsLimit(): int {
		return $this->bounded( 'exam_ip_active_holds', 40, 1, 10000 );
	}

	/** Созданий брони в час с одного IP. */
	public function examIpHourlyLimit(): int {
		return $this->bounded( 'exam_ip_hourly', 60, 1, 10000 );
	}

	/** Одновременно активных броней на источник-ссылку (школу). */
	public function examSourceActiveHoldsLimit(): int {
		return $this->bounded( 'exam_source_active_holds', 60, 1, 10000 );
	}

	/** Сколько дней хранятся данные гостей после завершения проведения. */
	public function examGuestRetentionDays(): int {
		return $this->bounded( 'exam_guest_retention_days', 365, 1, 3650 );
	}

	/** Сколько дней хранятся заявки без оплаты. */
	public function examUnpaidRetentionDays(): int {
		return $this->bounded( 'exam_unpaid_retention_days', 30, 1, 3650 );
	}

	/**
	 * Запасные контакты центра: работают, пока тема не подписалась на `fs_lms_center_contacts`.
	 *
	 * @return array{phone: string, email: string, hours: string, address: string}
	 */
	public function centerContactsFallback(): array {
		$data = $this->repository->get();

		return array(
			'phone'   => (string) ( $data['center_phone'] ?? '' ),
			'email'   => (string) ( $data['center_email'] ?? '' ),
			'hours'   => (string) ( $data['center_hours'] ?? '' ),
			'address' => (string) ( $data['center_address'] ?? '' ),
		);
	}

	/** Число из настроек, приведённое к границам; нечисловое — значение по умолчанию. */
	private function bounded( string $key, int $default, int $min, int $max ): int {
		$value = $this->repository->get()[ $key ] ?? $default;

		return max( $min, min( $max, is_numeric( $value ) ? (int) $value : $default ) );
	}

	public function isEncKeySet(): bool {
		return defined( 'FS_LMS_ENC_KEY' ) && '' !== FS_LMS_ENC_KEY;
	}

	public function isHashSaltSet(): bool {
		return defined( 'FS_LMS_HASH_SALT' ) && '' !== FS_LMS_HASH_SALT;
	}

	/**
	 * Payload для шаблона таба конфигурации.
	 * Мягкая тройка — c префиллом значений. Ключи — только set-флаг, значение НЕ включается.
	 */
	public function viewState(): array {
		$data            = $this->repository->get();
		$testEnvInConfig = defined( 'FS_LMS_TEST_ENV' );
		$otpInConfig     = defined( 'FS_LMS_OTP_BYPASS_CODE' );

		return array(
			'test_env'        => array(
				'value'             => $this->isTestEnv(),
				'defined_in_config' => $testEnvInConfig,
				'editable'          => ! $testEnvInConfig,
			),
			'otp_bypass_code' => array(
				'value'             => $otpInConfig ? (string) FS_LMS_OTP_BYPASS_CODE : (string) ( $data['otp_bypass_code'] ?? '' ),
				'defined_in_config' => $otpInConfig,
				'editable'          => ! $otpInConfig,
			),
			'enc_key_set'     => $this->isEncKeySet(),
			'hash_salt_set'   => $this->isHashSaltSet(),
			'brand_logo'      => array(
				'id'  => $this->brandLogoId(),
				'url' => $this->brandLogoUrl(),
			),
			'consultation_url' => $this->consultationUrl(),
			'exams'            => array(
				'product_9'            => $this->examProductId( 9 ),
				'product_11'           => $this->examProductId( 11 ),
				'hold_minutes'         => $this->examHoldMinutes(),
				'ip_active_holds'      => $this->examIpActiveHoldsLimit(),
				'ip_hourly'            => $this->examIpHourlyLimit(),
				'source_active_holds'  => $this->examSourceActiveHoldsLimit(),
				'guest_retention_days' => $this->examGuestRetentionDays(),
				'unpaid_retention_days' => $this->examUnpaidRetentionDays(),
				'contacts'             => $this->centerContactsFallback(),
			),
		);
	}
}
