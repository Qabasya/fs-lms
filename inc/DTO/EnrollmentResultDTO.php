<?php

declare( strict_types=1 );

namespace Inc\DTO;

/**
 * Class EnrollmentResultDTO
 *
 * Результат зачисления.
 *
 * @package Inc\DTO
 *
 * ### Основные обязанности:
 *
 * 1. **Инкапсуляция результата зачисления** — возвращает ID зачисления, ID созданных пользователей WP
 *    и ссылки для установки паролей (если не были отправлены автоматически).
 *
 * ### Архитектурная роль:
 *
 * Возвращается из EnrollmentService::enroll() после успешного зачисления студента.
 * Используется в контроллере для отображения результата сотруднику.
 *
 * ### Примечания:
 *
 * - studentPasswordLink / guardianPasswordLink = null, если ссылки отправлены на email автоматически.
 * - partialFailure = true, если транзакция прошла, но post-effects (создание WP-пользователей)
 *   не завершились полностью — recovery job подберёт через 15 минут.
 */
readonly class EnrollmentResultDTO {

	/**
	 * Конструктор DTO.
	 *
	 * @param int      $enrollmentId         ID записи зачисления
	 * @param int      $studentUserId        ID пользователя WP (студент)
	 * @param int      $guardianUserId       ID пользователя WP (родитель/опекун)
	 * @param string|null $studentPasswordLink   Ссылка для установки пароля студента
	 * @param string|null $guardianPasswordLink  Ссылка для установки пароля родителя
	 * @param bool     $partialFailure       Флаг частичного сбоя (требуется повторная обработка)
	 */
	public function __construct(
		public int     $enrollmentId,
		public int     $studentUserId,
		public int     $guardianUserId,
		public ?string $studentPasswordLink,
		public ?string $guardianPasswordLink,
		public bool    $partialFailure = false,
	) {}
}