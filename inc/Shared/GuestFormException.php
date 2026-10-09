<?php

declare( strict_types=1 );

namespace Inc\Shared;

use Inc\Enums\Log\ErrorCode;

/**
 * Ошибка гостевой формы с указанием поля (этап 11a.3.5): клиент показывает текст у своего поля и не очищает форму.
 * Поле `cabinet` — не ошибка поля: вошедший ученик или родитель направляется в кабинет.
 */
class GuestFormException extends CodedException {

	public function __construct(
		ErrorCode $errorCode,
		string $message,
		public readonly string $field = '',
	) {
		parent::__construct( $errorCode, $message );
	}
}
