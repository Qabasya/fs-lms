<?php

namespace Inc\Shared\Traits;

use Inc\Enums\Capability;
use Inc\Enums\Nonce;

trait Authorizer {
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
