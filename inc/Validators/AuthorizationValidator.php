<?php
declare(strict_types=1);

namespace Inc\Validators;

use Inc\Enums\Nonce;
use Inc\Enums\Capability;

class AuthorizationValidator {
	public function authorize(
		Nonce $nonceEnum,
		Capability $capability = Capability::ADMIN,
		string $queryArg = 'security'
	): void {
		// 1. Проверка Nonce (вызывает die() при ошибке внутри check_ajax_referer)
		$nonceEnum->verify( $queryArg );

		// 2. Проверка прав доступа
		if ( ! current_user_can( $capability->value ) ) {
			wp_send_json_error( 'У вас недостаточно прав', 403 );
		}
	}
}
