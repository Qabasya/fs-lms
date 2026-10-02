<?php

declare( strict_types=1 );

namespace Inc\Services\Captcha;

use Inc\Enums\Auth\AuthResult;
use Inc\Enums\Auth\AuthAction;
use Inc\Enums\Auth\CaptchaFailure;
use Inc\Enums\Auth\CaptchaOutcome;
use Inc\Enums\Auth\CaptchaScope;
use Inc\Services\Log\AuthLogWriter;
use Inc\Services\Security\RateLimitService;

/**
 * Class CaptchaService
 *
 * Фасад над провайдером капчи. Единственная точка входа для верификации капчи в callbacks.
 *
 * @package Inc\Services
 *
 * ### Архитектурная роль:
 *
 * Изолирует callbacks и контроллеры от конкретного провайдера. Смена провайдера
 * (hCaptcha → Yandex SmartCaptcha и т.д.) производится в DI-контейнере без
 * изменения кода, который вызывает validate().
 *
 * ### Использование в callback:
 *
 * ```php
 * if ( ! $this->captchaService->validate( $token, $ip ) ) {
 *     $this->error( 'Капча не пройдена.' );
 * }
 * ```
 *
 * ### Admin notice:
 *
 * Контроллер проверяет isConfigured() и добавляет предупреждение если капча
 * не настроена. Сам сервис не взаимодействует с WP admin.
 */
readonly class CaptchaService {

	/**
	 * Конструктор сервиса.
	 *
	 * Провайдер резолвится фабрикой через фильтр `fs_lms_captcha_provider`: по умолчанию
	 * заглушка (капча выключена), опциональный модуль SmartCaptcha подменяет на Yandex.
	 *
	 * @param CaptchaProviderFactory $factory Фабрика провайдера капчи
	 */
	public function __construct(
		private CaptchaProviderFactory $factory,
		private RateLimitService       $rateLimit,
		private AuthLogWriter          $authLog,
	) {}

	/**
	 * Верифицирует токен капчи.
	 *
	 * Делегирует в провайдер. Если капча не настроена — провайдер возвращает true.
	 *
	 * @param string $token    Токен с фронта
	 * @param string $remoteIp IP-адрес клиента
	 *
	 * @return bool
	 */
	public function validate( string $token, string $remoteIp ): bool {
		return $this->factory->make()->validate( $token, $remoteIp );
	}

	/**
	 * Проверка капчи формы со смягчением для тех, до кого капча не дошла.
	 *
	 * Есть токен — он проверяется строго, как в {@see self::validate()}. Токена нет, но браузер
	 * сообщил, что капча не загрузилась / не открылась / упала по сети (VPN, блокировщик рекламы
	 * — задание Яндекса оттуда не видно), — запрос пропускается, пока IP укладывается в тесный
	 * лимит {@see RateLimitService::allowCaptchaFallback()}; факт пропуска пишется в журнал
	 * «Аутентификация». Заявленное закрытие задания ({@see CaptchaFailure::Dismissed}) и запрос
	 * без токена и без причины отклоняются.
	 *
	 * Заявление о сбое приходит из браузера и бот может прислать его всегда: поэтому единственный
	 * барьер этого пути — лимит по IP, а для заявки ещё и OTP на почту (honeypot и тайминг формы
	 * проверяются отдельно).
	 *
	 * @param string              $token      Токен капчи (пустой — не получен)
	 * @param string              $remoteIp   IP клиента
	 * @param CaptchaScope        $scope      Форма
	 * @param CaptchaFailure|null $failure    Причина, по которой браузер не получил токен
	 * @param string|null         $identifier Логин/email для записи в журнал, если известен
	 * @param string              $visit      ID визита формы (связывает записи журнала)
	 */
	public function check(
		string $token,
		string $remoteIp,
		CaptchaScope $scope,
		?CaptchaFailure $failure = null,
		?string $identifier = null,
		string $visit = ''
	): CaptchaOutcome {
		if ( ! $this->isConfigured() ) {
			return CaptchaOutcome::Passed;
		}

		if ( '' !== $token ) {
			return $this->validate( $token, $remoteIp ) ? CaptchaOutcome::Passed : CaptchaOutcome::Rejected;
		}

		if ( null === $failure || ! $failure->allowsFallback() || ! $this->rateLimit->allowCaptchaFallback( $remoteIp, $scope ) ) {
			return CaptchaOutcome::Rejected;
		}

		$this->authLog->recordEvent(
			AuthAction::CaptchaFallback,
			AuthResult::Success,
			$failure->failReason(),
			array_filter( array(
				'form'  => $scope->value,
				'visit' => $visit,
				'note'  => ucfirst( $scope->label() ) . ': капча не дошла до браузера, пропущено по смягчённому правилу',
			) ),
			$identifier
		);

		return CaptchaOutcome::Fallback;
	}

	/**
	 * Возвращает публичный site key для рендера виджета капчи.
	 *
	 * @return string Пустая строка если провайдер не сконфигурирован
	 */
	public function getSiteKey(): string {
		return $this->factory->make()->getSiteKey();
	}

	/**
	 * Проверяет, настроен ли реальный провайдер капчи.
	 *
	 * @return bool false если ключи капчи не заданы
	 */
	public function isConfigured(): bool {
		return $this->factory->make()->isConfigured();
	}
}