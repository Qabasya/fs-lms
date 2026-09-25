<?php

declare( strict_types=1 );

namespace Inc\Enums\Log;

/**
 * Коды ошибок, которые видит пользователь (тост, уведомление) и которые пишутся
 * в журнал «Ошибки» ({@see LogChannel::Errors}).
 *
 * Код — короткая стабильная строка: по скриншоту пользователя находится причина,
 * а по номеру инцидента рядом с кодом — конкретная запись журнала. Значение кейса
 * менять нельзя: по нему ищут старые записи и старые скриншоты.
 *
 * Префикс — домен: `E-` общие, `W-` сдача работы.
 */
enum ErrorCode: string {

	/** Хук «произошла ошибка»: ( ErrorCode $code, string $message, string $ref, array $context ). */
	public const HOOK = 'fs_lms_error';

	// ===== Общие =====

	/** Ошибка AJAX-обработчика без отдельного кода (любой $this->error()) */
	case Ajax    = 'E-AJAX';
	/** Nonce не прошёл: вкладка висела дольше суток или сменилась сессия */
	case Session = 'E-SESSION';
	/** Запрос не дошёл до сервера (нет сети) — пишет только клиент */
	case Network = 'E-NET';
	/** Сервер ответил не JSON (фатальная ошибка PHP, 5xx прокси) — пишет клиент */
	case Http    = 'E-HTTP';

	// ===== Сдача работы =====

	case WorkFormat      = 'W-FORMAT';
	case WorkProfile     = 'W-PROFILE';
	case WorkNotMember   = 'W-NOT-MEMBER';
	case WorkAccess      = 'W-ACCESS';
	case WorkNoLesson    = 'W-NO-LESSON';
	case WorkNotInLesson = 'W-NOT-IN-LESSON';
	case WorkNotFound    = 'W-NOT-FOUND';
	/** Больше не выдаётся (сдача после срока разрешена) — кейс держит подпись старых записей журнала. */
	case WorkDeadline    = 'W-DEADLINE';
	case WorkLimit       = 'W-LIMIT';
	case WorkNothing     = 'W-NOTHING';

	/** Что означает код — подпись в журнале и фильтре. */
	public function label(): string {
		return match ( $this ) {
			self::Ajax            => 'Ошибка запроса',
			self::Session         => 'Сессия устарела (nonce)',
			self::Network         => 'Нет связи с сервером',
			self::Http            => 'Сбой сервера (ответ не JSON)',
			self::WorkFormat      => 'Работа: неверный формат ответов',
			self::WorkProfile     => 'Работа: у учётки нет профиля ученика',
			self::WorkNotMember   => 'Работа: не ученик этого занятия',
			self::WorkAccess      => 'Работа: сдача закрыта политикой доступа',
			self::WorkNoLesson    => 'Работа: занятие не найдено',
			self::WorkNotInLesson => 'Работа: не входит в занятие',
			self::WorkNotFound    => 'Работа: не найдена',
			self::WorkDeadline    => 'Работа: срок сдачи истёк',
			self::WorkLimit       => 'Работа: попытки исчерпаны',
			self::WorkNothing     => 'Работа: нечего пересдавать',
		};
	}

	/** Код для строки из журнала/запроса; неизвестный — null. */
	public static function fromCode( string $code ): ?self {
		return self::tryFrom( strtoupper( trim( $code ) ) );
	}
}
