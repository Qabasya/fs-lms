<?php

declare( strict_types=1 );

namespace Inc\DTO\Log;

/**
 * Class EmailLogInputDTO
 *
 * Data Transfer Object для вставки записи в журнал отправки email (email_log).
 *
 * @package Inc\DTO\Log
 *
 * ### Основные обязанности:
 *
 * 1. **Типобезопасная передача данных** — инкапсулирует данные для записи отправки email.
 * 2. **Преобразование в массив** — метод toArray() для вставки в БД.
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
 * - createdAt — дата и время отправки (MySQL datetime)
 *
 * ### Примечания:
 *
 * - Лог отправки email важен для аудита и отладки проблем с доставкой.
 * - Позволяет отследить, какие письма были отправлены и с каким результатом.
 */
readonly class EmailLogInputDTO {

	/**
	 * Конструктор DTO.
	 *
	 * @param int|null    $actorUserId    ID пользователя WP, инициировавшего отправку
	 * @param string|null $actorRole      Роль пользователя
	 * @param string      $emailType      Тип письма
	 * @param int|null    $targetPersonId ID лица (из persons)
	 * @param string      $status         Статус отправки (success/failed)
	 * @param string|null $errorMessage   Сообщение об ошибке
	 * @param string      $createdAt      Дата и время отправки (MySQL datetime)
	 */
	public function __construct(
		public ?int    $actorUserId,
		public ?string $actorRole,
		public string  $emailType,
		public ?int    $targetPersonId,
		public string  $status,
		public ?string $errorMessage,
		public string  $createdAt,
	) {}

	/**
	 * Преобразует DTO в массив для вставки в БД.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'actor_user_id'    => $this->actorUserId,
			'actor_role'       => $this->actorRole,
			'email_type'       => $this->emailType,
			'target_person_id' => $this->targetPersonId,
			'status'           => $this->status,
			'error_message'    => $this->errorMessage,
			'created_at'       => $this->createdAt,
		);
	}
}