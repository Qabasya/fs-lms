<?php

declare( strict_types=1 );

namespace Inc\Services\Captcha;

use Inc\Contracts\CaptchaProviderInterface;
use Inc\Services\CaptchaProviders\NullCaptchaProvider;

/**
 * Class CaptchaProviderFactory
 *
 * Резолвит активный провайдер капчи. По умолчанию — заглушка (NullCaptchaProvider,
 * капча выключена). Опциональный модуль SmartCaptcha подменяет провайдер через
 * фильтр `fs_lms_captcha_provider`, когда включён. Ядро о модуле не знает.
 *
 * @package Inc\Services\Captcha
 */
readonly class CaptchaProviderFactory {

	public function __construct(
		private NullCaptchaProvider $null,
	) {}

	/**
	 * Возвращает активный провайдер капчи.
	 *
	 * @return CaptchaProviderInterface
	 */
	public function make(): CaptchaProviderInterface {
		$provider = apply_filters( 'fs_lms_captcha_provider', $this->null );

		// Защита от некорректного значения из фильтра — падаем на заглушку.
		return $provider instanceof CaptchaProviderInterface ? $provider : $this->null;
	}
}
