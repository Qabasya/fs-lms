<?php

declare( strict_types=1 );

namespace Inc\DTO\Log;

/**
 * Class DataChangeLogDTO
 *
 * Data Transfer Object для записи в журнал изменений персональных данных (data_change_log).
 *
 * @package Inc\DTO\Log
 *
 * ### Основные обязанности:
 *
 * 1. **Хранение записи изменения данных** — представляет запись из таблицы data_change_log.
 * 2. **Преобразование массива в DTO** — статический метод fromArray().
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
 *
 * ### Примечания:
 *
 * - Старые и новые значения хранятся в зашифрованном виде для защиты PII.
 * - Расшифровка возможна только при наличии соответствующих прав.
 */
readonly class DataChangeLogDTO {

	/**
	 * Конструктор DTO.
	 *
	 * @param int         $id             ID записи
	 * @param int         $actorUserId    ID пользователя WP, изменившего данные
	 * @param string|null $actorRole      Роль пользователя
	 * @param int         $targetPersonId ID лица (из persons)
	 * @param string      $fieldName      Название изменённого поля
	 * @param string|null $oldValueEnc    Старое значение (зашифрованное)
	 * @param string|null $newValueEnc    Новое значение (зашифрованное)
	 * @param string      $createdAt      Дата и время изменения
	 */
	public function __construct(
		public int     $id,
		public int     $actorUserId,
		public ?string $actorRole,
		public int     $targetPersonId,
		public string  $fieldName,
		public ?string $oldValueEnc,
		public ?string $newValueEnc,
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
			actorUserId:    (int) $row['actor_user_id'],
			actorRole:      isset( $row['actor_role'] ) ? (string) $row['actor_role'] : null,
			targetPersonId: (int) $row['target_person_id'],
			fieldName:      (string) $row['field_name'],
			oldValueEnc:    isset( $row['old_value_enc'] ) ? (string) $row['old_value_enc'] : null,
			newValueEnc:    isset( $row['new_value_enc'] ) ? (string) $row['new_value_enc'] : null,
			createdAt:      (string) $row['created_at'],
		);
	}
}