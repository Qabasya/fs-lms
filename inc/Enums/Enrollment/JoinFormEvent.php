<?php

declare( strict_types=1 );

namespace Inc\Enums\Enrollment;

use Inc\Enums\Log\AuditAction;

/**
 * Enum JoinFormEvent
 *
 * Что произошло с формой родителя (/lms/join/{code}) после открытия — события,
 * которые присылает браузер (`join-tracking.js`). Значение — имя события в запросе.
 *
 * @package Inc\Enums\Enrollment
 */
enum JoinFormEvent: string {
	/** Первый ввод в любое поле. */
	case Started = 'started';
	/** Нажата отправка, но клиентская проверка (или «email занят») не пропустила. */
	case Invalid = 'invalid';
	/** Запрос ушёл, сервер отказал или не ответил. */
	case SubmitFailed = 'submit_failed';
	/** Страницу закрыли / ушли с неё, не отправив форму. */
	case Left = 'left';

	public function auditAction(): AuditAction {
		return match ( $this ) {
			self::Started      => AuditAction::JoinFormStarted,
			self::Invalid      => AuditAction::JoinFormInvalid,
			self::SubmitFailed => AuditAction::JoinSubmitFailed,
			self::Left         => AuditAction::JoinFormLeft,
		};
	}
}
