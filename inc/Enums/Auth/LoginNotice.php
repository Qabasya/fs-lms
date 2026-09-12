<?php

declare( strict_types=1 );

namespace Inc\Enums\Auth;

use Inc\Services\Security\RateLimitService;

/**
 * Enum LoginNotice
 *
 * Уведомление формы входа после неудачной попытки. Значение кейса — флаг `login`
 * в адресе `/sign-in/`: он нужен только для отображения, решение о блокировке
 * принимает сервер ({@see \Inc\Services\Security\LoginGuardService}).
 *
 * Текст одинаков для существующих и несуществующих логинов.
 *
 * @package Inc\Enums\Auth
 */
enum LoginNotice: string {
	case Failed  = 'failed';
	case Last    = 'last';
	case Locked  = 'locked';
	case Captcha = 'captcha';

	/**
	 * @param int $waitMinutes Минуты до конца блокировки (только для Locked; 0 — неизвестно)
	 *
	 * @return string
	 */
	public function message( int $waitMinutes = 0 ): string {
		return match ( $this ) {
			self::Failed  => 'Неверный логин или пароль.',
			self::Last    => sprintf(
				'Неверный логин или пароль. Осталась одна попытка, после неё вход будет закрыт на %d минут.',
				intdiv( RateLimitService::LOGIN_WINDOW, MINUTE_IN_SECONDS )
			),
			self::Locked  => $waitMinutes > 0
				? sprintf( 'Слишком много неудачных попыток. Повторите вход через %d мин.', $waitMinutes )
				: 'Слишком много неудачных попыток. Повторите вход позже.',
			self::Captcha => 'Не удалось пройти проверку, попробуйте ещё раз.',
		};
	}
}
