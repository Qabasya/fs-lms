<?php

declare( strict_types=1 );

namespace Inc\DTO;

/**
 * Class RequestContext
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
 * Используется AuditService и PiiAccessLogRepository для фиксации того,
 * кто (actorUserId), с какого IP и с каким браузером (userAgent) выполнил действие.
 *
 * ### Примечания:
 *
 * - IP хранится как бинарная строка через inet_pton для совместимости с типом VARBINARY(16)
 *   (поддержка как IPv4, так и IPv6).
 * - userAgent — строка User-Agent из HTTP-заголовка.
 * - actorUserId — ID пользователя WordPress, выполняющего действие.
 */
readonly class RequestContext {

	/**
	 * Конструктор DTO.
	 *
	 * @param string $ip          IP-адрес пользователя (бинарное представление через inet_pton)
	 * @param string $userAgent   User-Agent браузера
	 * @param int    $actorUserId ID пользователя WordPress (0 для неавторизованных)
	 */
	public function __construct(
		public string $ip,
		public string $userAgent,
		public int    $actorUserId,
	) {}
}