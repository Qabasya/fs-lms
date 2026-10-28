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
	 * @param int $waitMinutes Минуты: для Locked — до конца блокировки (0 — неизвестно), для Last — на сколько
	 *                         закроется вход после последней неудачи (0 — первая ступень)
	 *
	 * @return string
	 */
	public function message( int $waitMinutes = 0 ): string {
		return match ( $this ) {
			self::Failed  => 'Неверный логин или пароль.',
			self::Last    => sprintf(
				'Неверный логин или пароль. Осталась одна попытка, после неё вход будет закрыт на %s',
				self::duration( $waitMinutes > 0 ? $waitMinutes : intdiv( RateLimitService::LOGIN_LOCK_STEPS[0], MINUTE_IN_SECONDS ) )
			),
			self::Locked  => $waitMinutes > 0
				? sprintf( 'Слишком много неудачных попыток. Повторите вход через %s', self::duration( $waitMinutes ) )
				: 'Слишком много неудачных попыток. Повторите вход позже.',
			self::Captcha => 'Не удалось пройти проверку, попробуйте ещё раз.',
		};
	}

	/**
	 * Текст красной плашки формы, когда рядом уже идёт таймер блокировки: без «повторите вход через…»,
	 * время показывает таймер. Остальные уведомления — как {@see self::message()}.
	 *
	 * @return string
	 */
	public function messageWithTimer(): string {
		return self::Locked === $this ? 'Слишком много неудачных попыток.' : $this->message();
	}

	/**
	 * «15 мин.», «1 ч.», «23 ч. 59 мин.» — без склонений: блокировка длится от минут до суток.
	 *
	 * @param int $minutes Минуты, больше нуля
	 */
	private static function duration( int $minutes ): string {
		$hours = intdiv( $minutes, 60 );
		$rest  = $minutes % 60;

		if ( 0 === $hours ) {
			return sprintf( '%d мин.', $rest );
		}

		return 0 === $rest ? sprintf( '%d ч.', $hours ) : sprintf( '%d ч. %d мин.', $hours, $rest );
	}
}
