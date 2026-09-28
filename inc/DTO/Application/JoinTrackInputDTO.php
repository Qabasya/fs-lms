<?php

declare( strict_types=1 );

namespace Inc\DTO\Application;

use Inc\Enums\Enrollment\JoinFormEvent;

/**
 * Class JoinTrackInputDTO
 *
 * Событие формы родителя от браузера. Значения полей формы не передаются —
 * только имена полей и счётчики.
 *
 * @package Inc\DTO\Application
 */
readonly class JoinTrackInputDTO {

	/**
	 * @param string        $joinCode JOIN-код из формы
	 * @param JoinFormEvent $event    Что произошло
	 * @param string        $visit    ID визита (выдан при открытии страницы)
	 * @param string[]      $fields   Имена полей с ошибкой (для Invalid)
	 * @param int           $filled   Сколько полей заполнено
	 * @param int           $total    Сколько полей в форме
	 * @param int           $seconds  Секунд с открытия страницы
	 * @param int           $submits  Сколько раз нажимали «Отправить»
	 * @param string        $message  Текст ошибки, показанный родителю
	 */
	public function __construct(
		public string        $joinCode,
		public JoinFormEvent $event,
		public string        $visit,
		public array         $fields,
		public int           $filled,
		public int           $total,
		public int           $seconds,
		public int           $submits,
		public string        $message,
	) {}
}
