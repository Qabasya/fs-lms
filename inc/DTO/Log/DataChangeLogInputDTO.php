<?php

declare( strict_types=1 );

namespace Inc\DTO\Log;

/**
 * Class DataChangeLogInputDTO
 *
 * Data Transfer Object для вставки записи в журнал изменений персональных данных (data_change_log).
 *
 * @package Inc\DTO\Log
 *
 * ### Основные обязанности:
 *
 * 1. **Типобезопасная передача данных** — инкапсулирует данные для записи изменения данных лица.
 * 2. **Преобразование в массив** — метод toArray() для вставки в БД.
 *
 * ### Архитектурная роль:
 *
 * Используется в DataChangeLogWriter для передачи данных о событиях
 * изменения персональных данных лица (поля doc_number, inn, address, phone и т.д.).
 *
 * ### Поля записи:
 *
 * - actorUserId — ID пользователя WordPress, изменившего данные
 * - actorRole — роль пользователя на момент изменения
 * - targetPersonId — ID лица (из persons), чьи данные изменены
 * - fieldName — название изменённого поля
 * - oldValueEnc — старое значение поля (зашифрованное, BLOB)
 * - newValueEnc — новое значение поля (зашифрованное, BLOB)
 * - createdAt — дата и время изменения
 *
 * ### Примечания:
 *
 * - Старые и новые значения хранятся в зашифрованном виде для защиты PII.
 * - Расшифровка возможна только при наличии соответствующих прав.
 */
readonly class DataChangeLogInputDTO {

	/**
	 * Конструктор DTO.
	 *
	 * @param int         $actorUserId    ID пользователя WP, изменившего данные
	 * @param string|null $actorRole      Роль пользователя
	 * @param int         $targetPersonId ID лица (из persons)
	 * @param string      $fieldName      Название изменённого поля
	 * @param string|null $oldValueEnc    Старое значение (зашифрованное)
	 * @param string|null $newValueEnc    Новое значение (зашифрованное)
	 * @param string      $createdAt      Дата и время изменения (MySQL datetime)
	 */
	public function __construct(
		public int     $actorUserId,
		public ?string $actorRole,
		public int     $targetPersonId,
		public string  $fieldName,
		public ?string $oldValueEnc,
		public ?string $newValueEnc,
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
			'target_person_id' => $this->targetPersonId,
			'field_name'       => $this->fieldName,
			'old_value_enc'    => $this->oldValueEnc,
			'new_value_enc'    => $this->newValueEnc,
			'created_at'       => $this->createdAt,
		);
	}
}