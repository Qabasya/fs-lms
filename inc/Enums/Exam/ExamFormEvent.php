<?php

declare( strict_types=1 );

namespace Inc\Enums\Exam;

use Inc\Enums\Auth\AuthAction;

/**
 * Enum ExamFormEvent
 *
 * Что произошло с гостевой формой записи на экзамен — события, которые присылает браузер
 * (`exam-signup-tracking.js`). Значение — имя события в запросе. Капчи у этой формы нет:
 * защита — honeypot, метка времени и лимиты.
 *
 * @package Inc\Enums\Exam
 */
enum ExamFormEvent: string {
	/** Первый ввод в любое поле. */
	case Started = 'started';
	/** Нажата отправка, но клиентская проверка не пропустила. */
	case Invalid = 'invalid';
	/** Запрос ушёл, сервер отказал или не ответил. */
	case SubmitFailed = 'submit_failed';
	/** Страницу закрыли / ушли с неё, не завершив запись. */
	case Left = 'left';

	/** Событие общего журнала «Аутентификация». */
	public function authAction(): AuthAction {
		return match ( $this ) {
			self::Started      => AuthAction::ExamFormStarted,
			self::Invalid      => AuthAction::ExamFormInvalid,
			self::SubmitFailed => AuthAction::ExamFormSubmitFailed,
			self::Left         => AuthAction::ExamFormLeft,
		};
	}

	/** Действие журнала «Экзамены». */
	public function logAction(): ExamLogAction {
		return match ( $this ) {
			self::Started      => ExamLogAction::FormStarted,
			self::Invalid      => ExamLogAction::FormInvalid,
			self::SubmitFailed => ExamLogAction::FormSubmitFailed,
			self::Left         => ExamLogAction::FormLeft,
		};
	}

	/** Удача ли это: начало заполнения не неудача, всё остальное — «форма не дошла до конца». */
	public function isFailure(): bool {
		return self::Started !== $this;
	}
}
