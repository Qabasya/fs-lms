<?php

declare( strict_types=1 );

namespace Inc\DTO\Person;

use Inc\Enums\Auth\LoginNotice;

/**
 * Class LoginNoticeDTO
 *
 * Состояние формы входа после неудачной попытки: какое уведомление показать
 * и сколько минут ждать (для блокировки).
 *
 * @package Inc\DTO\Person
 */
readonly class LoginNoticeDTO {

	/**
	 * @param LoginNotice $notice      Уведомление
	 * @param int         $waitMinutes Locked — минуты до конца блокировки; Last — на сколько минут закроется
	 *                                 вход после последней неудачи; 0 — не нужно
	 */
	public function __construct(
		public LoginNotice $notice,
		public int $waitMinutes = 0,
	) {}

	/**
	 * Параметры адреса `/sign-in/`: `login` и, для блокировки и последней попытки, `wait`.
	 *
	 * @return array<string, string|int>
	 */
	public function queryArgs(): array {
		$args = array( 'login' => $this->notice->value );

		if ( in_array( $this->notice, array( LoginNotice::Locked, LoginNotice::Last ), true ) && $this->waitMinutes > 0 ) {
			$args['wait'] = $this->waitMinutes;
		}

		return $args;
	}
}
