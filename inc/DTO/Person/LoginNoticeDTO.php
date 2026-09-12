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
	 * @param int         $waitMinutes Минуты до конца блокировки; 0 — не заблокирован
	 */
	public function __construct(
		public LoginNotice $notice,
		public int $waitMinutes = 0,
	) {}

	/**
	 * Параметры адреса `/sign-in/`: `login` и, для блокировки, `wait`.
	 *
	 * @return array<string, string|int>
	 */
	public function queryArgs(): array {
		$args = array( 'login' => $this->notice->value );

		if ( LoginNotice::Locked === $this->notice && $this->waitMinutes > 0 ) {
			$args['wait'] = $this->waitMinutes;
		}

		return $args;
	}
}
