<?php

declare( strict_types=1 );

namespace Inc\DTO\Log;

use Inc\Enums\Exam\ExamLogAction;

/**
 * Вставка записи в журнал «Экзамены» (fs_lms_exam_log).
 *
 * @package Inc\DTO\Log
 */
readonly class ExamLogInputDTO {

	/**
	 * @param ExamLogAction        $action         Действие
	 * @param string               $result         `success` | `failure`
	 * @param string               $actorType      `user` | `guest` | `system`
	 * @param int|null             $actorUserId    Пользователь WP (null — гость или система)
	 * @param string|null          $actorLabel     Имя актора (ФИО сотрудника; у гостя и системы — подпись)
	 * @param int|null             $eventId        Проведение
	 * @param int|null             $sessionId      Сеанс
	 * @param int|null             $registrationId Запись (заявка) участника
	 * @param int|null             $attemptId      Попытка
	 * @param string|null          $subjectLabel   Участник: ФИО, если известно, иначе номер
	 * @param string               $actorIp        IP
	 * @param array<string, mixed> $context        Дополнительные данные (причина, было/стало, код ошибки)
	 * @param string               $createdAt      Время (UTC)
	 */
	public function __construct(
		public ExamLogAction $action,
		public string        $result,
		public string        $actorType,
		public ?int          $actorUserId,
		public ?string       $actorLabel,
		public ?int          $eventId,
		public ?int          $sessionId,
		public ?int          $registrationId,
		public ?int          $attemptId,
		public ?string       $subjectLabel,
		public string        $actorIp,
		public array         $context,
		public string        $createdAt,
	) {}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		$context = array() === $this->context ? null : wp_json_encode( $this->context, JSON_UNESCAPED_UNICODE );

		return array(
			'action'          => $this->action->value,
			'result'          => $this->result,
			'actor_type'      => $this->actorType,
			'actor_user_id'   => $this->actorUserId,
			'actor_label'     => null !== $this->actorLabel ? mb_substr( $this->actorLabel, 0, 255 ) : null,
			'event_id'        => $this->eventId,
			'session_id'      => $this->sessionId,
			'registration_id' => $this->registrationId,
			'attempt_id'      => $this->attemptId,
			'subject_label'   => null !== $this->subjectLabel ? mb_substr( $this->subjectLabel, 0, 255 ) : null,
			'actor_ip'        => $this->actorIp,
			'context'         => false === $context ? null : $context,
			'created_at'      => $this->createdAt,
		);
	}
}
