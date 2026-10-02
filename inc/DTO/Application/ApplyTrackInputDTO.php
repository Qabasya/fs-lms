<?php

declare( strict_types=1 );

namespace Inc\DTO\Application;

use Inc\Enums\Auth\CaptchaFailure;
use Inc\Enums\Enrollment\ApplyFormEvent;

/**
 * Class ApplyTrackInputDTO
 *
 * Событие формы заявки от браузера. Значения полей не передаются — только имена
 * полей и счётчики.
 *
 * @package Inc\DTO\Application
 */
readonly class ApplyTrackInputDTO {

	/**
	 * @param ApplyFormEvent      $event   Что произошло
	 * @param string              $visit   ID визита (браузер выдаёт при открытии страницы)
	 * @param string              $stage   Этап формы: `form` (данные) или `otp` (код из письма)
	 * @param string[]            $fields  Имена полей с ошибкой (для Invalid)
	 * @param int                 $filled  Сколько полей заполнено
	 * @param int                 $total   Сколько полей в форме
	 * @param int                 $seconds Секунд с открытия страницы
	 * @param int                 $submits Сколько раз нажимали «Продолжить»
	 * @param string              $message Текст ошибки, показанный пользователю
	 * @param CaptchaFailure|null $captcha Причина, по которой капча не дала токен (для CaptchaFailed)
	 */
	public function __construct(
		public ApplyFormEvent  $event,
		public string          $visit,
		public string          $stage,
		public array           $fields,
		public int             $filled,
		public int             $total,
		public int             $seconds,
		public int             $submits,
		public string          $message,
		public ?CaptchaFailure $captcha,
	) {}
}
