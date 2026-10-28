<?php

declare( strict_types=1 );

namespace Inc\Modules\Masterclass\DTO;

/**
 * Данные одного флаера (логин и пароль ребёнка для печати).
 *
 * Содержит пароль в открытом виде — живёт только в памяти запроса печати и в журнал не попадает.
 *
 * @package Inc\Modules\Masterclass\DTO
 */
readonly class MasterclassFlyerDTO {

	/**
	 * @param int    $number     Номер аккаунта N
	 * @param string $login      Логин (`student-N`)
	 * @param string $password   Пароль в открытом виде
	 * @param string $schoolName Школа
	 * @param string $loginUrl   Адрес страницы входа
	 */
	public function __construct(
		public int $number,
		public string $login,
		public string $password,
		public string $schoolName,
		public string $loginUrl,
	) {}
}
