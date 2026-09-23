<?php

declare( strict_types=1 );

namespace Inc\Shared;

use Inc\Enums\Log\ErrorCode;

/**
 * Отказ сервиса с кодом ошибки для пользователя и журнала «Ошибки».
 *
 * Наследник InvalidArgumentException: существующие `catch ( \InvalidArgumentException )`
 * ловят его как раньше, а обработчик, которому нужен код, достаёт его из `$errorCode`
 * и отвечает через `AjaxResponse::fail()`.
 */
class CodedException extends \InvalidArgumentException {

	public function __construct(
		public readonly ErrorCode $errorCode,
		string $message,
	) {
		parent::__construct( $message );
	}
}
