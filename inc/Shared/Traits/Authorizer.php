<?php

namespace Inc\Shared\Traits;

use Inc\Enums\Capability;
use Inc\Enums\Nonce;

/**
 * Trait Authorizer
 *
 * Предоставляет метод для авторизации AJAX-запросов.
 *
 * @package Inc\Shared\Traits
 *
 * ### Основные обязанности:
 *
 * 1. **Проверка nonce** — защита от CSRF-атак.
 * 2. **Проверка прав доступа** — гарантия, что пользователь имеет необходимые права.
 *
 * ### Архитектурная роль:
 *
 * Объединяет проверку nonce и прав доступа в одном вызове.
 * Используется в классах-обработчиках AJAX-запросов.
 */
trait Authorizer {
	
	/**
	 * Выполняет авторизацию AJAX-запроса.
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
		// 1. Проверка Nonce
		// verify() — метод enum Nonce, внутри вызывает check_ajax_referer()
		// check_ajax_referer() — WordPress-функция, проверяет nonce и вызывает die() при ошибке
		$nonceEnum->verify( $queryArg );
		
		// 2. Проверка прав доступа
		// current_user_can() — WordPress-функция, проверяет, имеет ли пользователь указанное право
		// 403 — HTTP-статус "Forbidden" (доступ запрещён)
		if ( ! current_user_can( $capability->value ) ) {
			wp_send_json_error( 'У вас недостаточно прав', 403 );
		}
	}
}