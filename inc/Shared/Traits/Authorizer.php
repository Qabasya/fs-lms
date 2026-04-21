<?php

namespace Inc\Shared\Traits;

use Inc\Enums\Capability;
use Inc\Enums\Nonce;

/**
 * Trait Authorizer
 *
 * Предоставляет метод для авторизации AJAX-запросов.
 * Объединяет проверку nonce и прав доступа в одном вызове.
 *
 * @package Inc\Shared\Traits
 */
trait Authorizer {

	/**
	 * Выполняет авторизацию AJAX-запроса.
	 *
	 * Проверяет:
	 * 1. Nonce для защиты от CSRF (вызывает die() при ошибке)
	 * 2. Права доступа текущего пользователя
	 *
	 * @param Nonce      $nonceEnum  Enum с данными nonce (значение и имя action)
	 * @param Capability $capability Необходимое право доступа (по умолчанию ADMIN)
	 * @param string     $queryArg   Имя параметра в запросе, где передан nonce (по умолчанию 'security')
	 *
	 * @return void
	 */
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
