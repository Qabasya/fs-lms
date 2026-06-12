<?php

declare( strict_types=1 );

namespace Inc\DTO;

/**
 * Class RequestContextDTO
 *
 * Контекст HTTP-запроса.
 *
 * @package Inc\DTO
 *
 * ### Основные обязанности:
 *
 * 1. **Инкапсуляция контекста запроса** — объединяет IP-адрес, User-Agent и ID пользователя.
 *
 * ### Архитектурная роль:
 *
 * Используется Log Writers для фиксации того,
 * кто (actorUserId), с какого IP и с каким браузером (userAgent) выполнил действие.
 *
 * ### Примечания:
 *
 * - IP хранится как текстовая строка (varchar(45)), поддерживает IPv4 и IPv6.
 * - userAgent — строка User-Agent из HTTP-заголовка.
 * - actorUserId — ID пользователя WordPress, выполняющего действие.
 */
readonly class RequestContextDTO {

	/**
	 * Конструктор DTO.
	 *
	 * @param string $ip          IP-адрес пользователя (текстовый, varchar(45))
	 * @param string $userAgent   User-Agent браузера
	 * @param int    $actorUserId ID пользователя WordPress (0 для неавторизованных)
	 */
	public function __construct(
		public string $ip,
		public string $userAgent,
		public int    $actorUserId,
	) {}
}