<?php

declare( strict_types=1 );

namespace Inc\DTO\Log;

/**
 * Class EmailLogDTO
 *
 * Data Transfer Object для записи в журнал отправки email (email_log).
 *
 * @package Inc\DTO\Log
 *
 * ### Основные обязанности:
 *
 * 1. **Хранение записи отправки email** — представляет запись из таблицы email_log.
 * 2. **Преобразование массива в DTO** — статический метод fromArray().
 *
 * ### Архитектурная роль:
 *
 * Используется в EmailLogWriter для передачи данных о событиях
 * отправки email-сообщений (уведомления, OTP-коды, сброс пароля и т.д.).
 *
 * ### Поля записи:
 *
 * - actorUserId — ID пользователя WordPress, инициировавшего отправку
 * - actorRole — роль пользователя на момент отправки
 * - emailType — тип письма (otp_code, password_setup, application_confirmation и т.д.)
 * - targetPersonId — ID лица (из persons), которому адресовано письмо
 * - status — статус отправки (success/failed)
 * - errorMessage — сообщение об ошибке (если статус failed)
 * - createdAt — дата и время отправки
 *
 * ### Примечания:
 *
 * - Лог отправки email важен для аудита и отладки проблем с доставкой.
 * - Позволяет отследить, какие письма были отправлены и с каким результатом.
 */
readonly class EmailLogDTO {

	/**
	 * Конструктор DTO.
	 *
	 * @param int         $id             ID записи
	 * @param int|null    $actorUserId    ID пользователя WP, инициировавшего отправку
	 * @param string|null $actorRole      Роль пользователя
	 * @param string      $emailType      Тип письма
	 * @param int|null    $targetPersonId ID лица (из persons)
	 * @param string      $status         Статус отправки (success/failed)
	 * @param string|null $errorMessage   Сообщение об ошибке
	 * @param string      $createdAt      Дата и время отправки
	 */
	public function __construct(
		public int     $id,
		public ?int    $actorUserId,
		public ?string $actorRole,
		public string  $emailType,
		public ?int    $targetPersonId,
		public ?string $recipientEmail,
		public string  $status,
		public ?string $errorMessage,
		public string  $createdAt,
	) {}

	/**
	 * Создаёт DTO из массива данных (например, из результата SQL-запроса).
	 *
	 * @param array<string, mixed> $row Ассоциативный массив с полями таблицы
	 *
	 * @return static
	 */
	public static function fromArray( array $row ): static {
		return new static(
			id:             (int) $row['id'],
			actorUserId:    isset( $row['actor_user_id'] ) ? (int) $row['actor_user_id'] : null,
			actorRole:      isset( $row['actor_role'] ) ? (string) $row['actor_role'] : null,
			emailType:      (string) $row['email_type'],
			targetPersonId: isset( $row['target_person_id'] ) ? (int) $row['target_person_id'] : null,
			recipientEmail: isset( $row['recipient_email'] ) ? (string) $row['recipient_email'] : null,
			status:         (string) $row['status'],
			errorMessage:   isset( $row['error_message'] ) ? (string) $row['error_message'] : null,
			createdAt:      (string) $row['created_at'],
		);
	}
}