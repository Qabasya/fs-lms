<?php

declare( strict_types=1 );

namespace Inc\DTO\Log;

/**
 * Запись журнала «Экзамены» (fs_lms_exam_log).
 *
 * @package Inc\DTO\Log
 */
readonly class ExamLogDTO {

	/**
	 * @param int                  $id             ID записи
	 * @param string               $action         Значение {@see \Inc\Enums\Exam\ExamLogAction} строкой: старые записи переживают удаление кейса
	 * @param string               $result         `success` | `failure`
	 * @param string               $actorType      `user` | `guest` | `system`
	 * @param int|null             $actorUserId    Пользователь WP
	 * @param string|null          $actorLabel     Подпись актора
	 * @param int|null             $eventId        Проведение
	 * @param int|null             $sessionId      Сеанс
	 * @param int|null             $registrationId Запись участника
	 * @param int|null             $attemptId      Попытка
	 * @param string|null          $subjectLabel   Участник (ФИО или номер)
	 * @param string               $actorIp        IP
	 * @param array<string, mixed> $context        Дополнительные данные
	 * @param string               $createdAt      Дата (UTC)
	 */
	public function __construct(
		public int     $id,
		public string  $action,
		public string  $result,
		public string  $actorType,
		public ?int    $actorUserId,
		public ?string $actorLabel,
		public ?int    $eventId,
		public ?int    $sessionId,
		public ?int    $registrationId,
		public ?int    $attemptId,
		public ?string $subjectLabel,
		public string  $actorIp,
		public array   $context,
		public string  $createdAt,
	) {}

	public static function fromArray( array $row ): static {
		$context = isset( $row['context'] ) && '' !== $row['context'] ? json_decode( (string) $row['context'], true ) : array();

		return new static(
			id:             (int) $row['id'],
			action:         (string) ( $row['action'] ?? '' ),
			result:         (string) ( $row['result'] ?? 'success' ),
			actorType:      (string) ( $row['actor_type'] ?? 'system' ),
			actorUserId:    ! empty( $row['actor_user_id'] ) ? (int) $row['actor_user_id'] : null,
			actorLabel:     isset( $row['actor_label'] ) ? (string) $row['actor_label'] : null,
			eventId:        ! empty( $row['event_id'] ) ? (int) $row['event_id'] : null,
			sessionId:      ! empty( $row['session_id'] ) ? (int) $row['session_id'] : null,
			registrationId: ! empty( $row['registration_id'] ) ? (int) $row['registration_id'] : null,
			attemptId:      ! empty( $row['attempt_id'] ) ? (int) $row['attempt_id'] : null,
			subjectLabel:   isset( $row['subject_label'] ) ? (string) $row['subject_label'] : null,
			actorIp:        (string) ( $row['actor_ip'] ?? '' ),
			context:        is_array( $context ) ? $context : array(),
			createdAt:      (string) $row['created_at'],
		);
	}
}
