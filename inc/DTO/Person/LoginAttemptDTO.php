<?php

declare( strict_types=1 );

namespace Inc\DTO\Person;

/**
 * Class LoginAttemptDTO
 *
 * Что пользователь отправил в форму входа — сырьё для диагностики неудачи.
 * Пароль живёт только в памяти запроса и никуда не пишется.
 *
 * @package Inc\DTO\Person
 */
readonly class LoginAttemptDTO {

	/**
	 * @param string $login          Логин/email как набран (до sanitize_user)
	 * @param string $password       Введённый пароль
	 * @param string $captchaToken   Токен капчи из формы
	 * @param bool   $fromSignInPage Попытка с нашей страницы /sign-in/, а не с нативного wp-login.php
	 */
	public function __construct(
		public string $login,
		#[\SensitiveParameter]
		public string $password,
		public string $captchaToken,
		public bool   $fromSignInPage,
	) {}
}
