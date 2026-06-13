<?php

declare( strict_types=1 );

namespace Inc\DTO\Log;

/**
 * Class ConsentChangeLogInputDTO
 *
 * Data Transfer Object для вставки записи в журнал изменений согласий (consent_change_log).
 *
 * @package Inc\DTO\Log
 *
 * ### Основные обязанности:
 *
 * 1. **Типобезопасная передача данных** — инкапсулирует данные для записи изменения согласия.
 * 2. **Преобразование в массив** — метод toArray() для вставки в БД.
 *
 * ### Архитектурная роль:
 *
 * Используется в ConsentChangeLogWriter для передачи данных о событиях
 * изменения согласий (старый и новый хеш документа согласия).
 *
 * ### Поля записи:
 *
 * - actorUserId — ID пользователя WordPress, изменившего согласие
 * - actorRole — роль пользователя на момент изменения
 * - personId — ID лица (из persons), чьё согласие изменилось
 * - consentType — тип согласия (pd_processing, marketing и т.д.)
 * - oldHash — SHA-256 хеш старой версии документа согласия
 * - newHash — SHA-256 хеш новой версии документа согласия
 * - createdAt — дата и время изменения
 */
readonly class ConsentChangeLogInputDTO {

	/**
	 * Конструктор DTO.
	 *
	 * @param int|null    $actorUserId ID пользователя WP, изменившего согласие
	 * @param string|null $actorRole   Роль пользователя
	 * @param int|null    $personId    ID лица (из persons)
	 * @param string      $consentType Тип согласия
	 * @param string|null $oldHash     Хеш старой версии документа
	 * @param string|null $newHash     Хеш новой версии документа
	 * @param string      $createdAt   Дата и время изменения (MySQL datetime)
	 */
	public function __construct(
		public ?int    $actorUserId,
		public ?string $actorRole,
		public ?int    $personId,
		public string  $consentType,
		public ?string $oldHash,
		public ?string $newHash,
		public string  $createdAt,
	) {}

	/**
	 * Преобразует DTO в массив для вставки в БД.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'actor_user_id' => $this->actorUserId,
			'actor_role'    => $this->actorRole,
			'person_id'     => $this->personId,
			'consent_type'  => $this->consentType,
			'old_hash'      => $this->oldHash,
			'new_hash'      => $this->newHash,
			'created_at'    => $this->createdAt,
		);
	}
}