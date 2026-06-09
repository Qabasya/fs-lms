<?php

declare( strict_types=1 );

namespace Inc\DTO\Application;

/**
 * Class ApplicationCreatedDTO
 *
 * Результат успешного создания заявки.
 *
 * @package Inc\DTO
 *
 * ### Основные обязанности:
 *
 * 1. **Инкапсуляция результата** — возвращает ID созданной заявки, URL для присоединения и срок действия.
 *
 * ### Архитектурная роль:
 *
 * Возвращается из ApplicationService::createApplication().
 * Используется в контроллерах для передачи данных клиенту после создания заявки.
 *
 * ### Примечания:
 *
 * - joinUrl содержит JOIN-код (не хэш) — передаётся ученику для отправки родителю.
 * - expiresAt — дата истечения срока действия ссылки для присоединения родителя.
 */
readonly class ApplicationCreatedDTO {

	/**
	 * Конструктор DTO.
	 *
	 * @param int    $applicationId ID созданной заявки
	 * @param string $joinUrl       URL для присоединения родителя (с открытым кодом)
	 * @param string $expiresAt     Дата истечения срока действия ссылки (MySQL datetime)
	 */
	public function __construct(
		public int $applicationId,
		public string $joinUrl,
		public string $expiresAt,
	) {}
}
