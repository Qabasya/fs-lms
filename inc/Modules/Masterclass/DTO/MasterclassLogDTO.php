<?php

declare( strict_types=1 );

namespace Inc\Modules\Masterclass\DTO;

/**
 * Запись журнала «Мастер-классы» (fs_lms_masterclass_log). Образец — `ExamLogDTO`.
 *
 * @package Inc\Modules\Masterclass\DTO
 */
readonly class MasterclassLogDTO {

	/**
	 * @param int                  $id            ID записи
	 * @param string               $action        Значение {@see \Inc\Modules\Masterclass\Enums\MasterclassLogAction} строкой: старые записи переживают удаление кейса
	 * @param string               $result        `success` | `failure`
	 * @param string               $actorType     `user` | `guest` | `system`
	 * @param int|null             $actorUserId   Пользователь WP
	 * @param string|null          $actorLabel    Подпись актора
	 * @param int|null             $masterclassId Мастер-класс
	 * @param int|null             $accountId     Аккаунт (номер N)
	 * @param string|null          $subjectLabel  Подпись объекта
	 * @param string               $actorIp       IP
	 * @param array<string, mixed> $context       Дополнительные данные
	 * @param string               $createdAt     Дата (UTC)
	 */
	public function __construct(
		public int $id,
		public string $action,
		public string $result,
		public string $actorType,
		public ?int $actorUserId,
		public ?string $actorLabel,
		public ?int $masterclassId,
		public ?int $accountId,
		public ?string $subjectLabel,
		public string $actorIp,
		public array $context,
		public string $createdAt,
	) {}

	/**
	 * @param array<string, mixed> $row Строка таблицы
	 */
	public static function fromArray( array $row ): self {
		$context = isset( $row['context'] ) && '' !== $row['context'] ? json_decode( (string) $row['context'], true ) : array();

		return new self(
			id:            (int) $row['id'],
			action:        (string) ( $row['action'] ?? '' ),
			result:        (string) ( $row['result'] ?? 'success' ),
			actorType:     (string) ( $row['actor_type'] ?? 'system' ),
			actorUserId:   ! empty( $row['actor_user_id'] ) ? (int) $row['actor_user_id'] : null,
			actorLabel:    isset( $row['actor_label'] ) ? (string) $row['actor_label'] : null,
			masterclassId: ! empty( $row['masterclass_id'] ) ? (int) $row['masterclass_id'] : null,
			accountId:     ! empty( $row['account_id'] ) ? (int) $row['account_id'] : null,
			subjectLabel:  isset( $row['subject_label'] ) ? (string) $row['subject_label'] : null,
			actorIp:       (string) ( $row['actor_ip'] ?? '' ),
			context:       is_array( $context ) ? $context : array(),
			createdAt:     (string) $row['created_at'],
		);
	}
}
