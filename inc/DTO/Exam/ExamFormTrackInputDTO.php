<?php

declare( strict_types=1 );

namespace Inc\DTO\Exam;

use Inc\Enums\Exam\ExamFormEvent;

/**
 * Class ExamFormTrackInputDTO
 *
 * Событие гостевой формы записи на экзамен от браузера. Значения полей не передаются —
 * только имена полей и счётчики.
 *
 * @package Inc\DTO\Exam
 */
readonly class ExamFormTrackInputDTO {

	/**
	 * @param ExamFormEvent $event   Что произошло
	 * @param string        $visit   ID визита (браузер выдаёт при открытии страницы)
	 * @param string[]      $fields  Имена полей с ошибкой (для Invalid)
	 * @param int           $filled  Сколько полей заполнено
	 * @param int           $total   Сколько полей в форме
	 * @param int           $seconds Секунд с открытия страницы
	 * @param int           $submits Сколько раз нажимали «Перейти к оплате»
	 * @param string        $message Текст ошибки, показанный гостю
	 */
	public function __construct(
		public ExamFormEvent $event,
		public string        $visit,
		public array         $fields,
		public int           $filled,
		public int           $total,
		public int           $seconds,
		public int           $submits,
		public string        $message,
	) {}
}
