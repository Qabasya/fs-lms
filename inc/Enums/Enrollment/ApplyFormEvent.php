<?php

declare( strict_types=1 );

namespace Inc\Enums\Enrollment;

use Inc\Enums\Auth\AuthAction;

/**
 * Enum ApplyFormEvent
 *
 * Что произошло с формой заявки (/lms/apply) — события, которые присылает браузер
 * (`apply-tracking.js`). Значение — имя события в запросе.
 *
 * @package Inc\Enums\Enrollment
 */
enum ApplyFormEvent: string {
	/** Первый ввод в любое поле. */
	case Started = 'started';
	/** Нажата отправка, но клиентская проверка (или «логин занят») не пропустила. */
	case Invalid = 'invalid';
	/** Капча показала задание (невидимая проверка не справилась сама). */
	case CaptchaShown = 'captcha_shown';
	/** Капча не дала токен: не загрузилась, не открылась вовремя, сетевой сбой, задание закрыли. */
	case CaptchaFailed = 'captcha_failed';
	/** Запрос ушёл, сервер отказал или не ответил. */
	case SubmitFailed = 'submit_failed';
	/** Страницу закрыли / ушли с неё, не завершив заявку. */
	case Left = 'left';

	public function auditAction(): AuthAction {
		return match ( $this ) {
			self::Started       => AuthAction::ApplyStarted,
			self::Invalid       => AuthAction::ApplyInvalid,
			self::CaptchaShown  => AuthAction::ApplyCaptchaShown,
			self::CaptchaFailed => AuthAction::ApplyCaptchaFailed,
			self::SubmitFailed  => AuthAction::ApplySubmitFailed,
			self::Left          => AuthAction::ApplyLeft,
		};
	}
}
