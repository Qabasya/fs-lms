<?php

declare( strict_types=1 );

namespace Inc\Modules\Masterclass\DTO;

use Inc\Modules\Masterclass\Enums\MasterclassLogAction;

/**
 * Вставка записи в журнал «Мастер-классы» (fs_lms_masterclass_log). Образец — `ExamLogInputDTO`.
 *
 * Пароли и персональные данные в `context` не кладутся — это правило писателя журнала, а не DTO.
 *
 * @package Inc\Modules\Masterclass\DTO
 */
readonly class MasterclassLogInputDTO {

	/**
	 * @param MasterclassLogAction $action        Действие
	 * @param string               $result        `success` | `failure`
	 * @param string               $actorType     `user` | `guest` | `system`
	 * @param int|null             $actorUserId   Пользователь WP (null — система)
	 * @param string|null          $actorLabel    Подпись актора
	 * @param int|null             $masterclassId Мастер-класс
	 * @param int|null             $accountId     Аккаунт (номер N)
	 * @param string|null          $subjectLabel  Подпись объекта («Ученик#N»)
	 * @param string               $actorIp       IP
	 * @param array<string, mixed> $context       Дополнительные данные
	 * @param string               $createdAt     Время (UTC)
	 */
	public function __construct(
		public MasterclassLogAction $action,
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
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		$context = array() === $this->context ? null : wp_json_encode( $this->context, JSON_UNESCAPED_UNICODE );

		return array(
			'action'         => $this->action->value,
			'result'         => $this->result,
			'actor_type'     => $this->actorType,
			'actor_user_id'  => $this->actorUserId,
			'actor_label'    => null !== $this->actorLabel ? mb_substr( $this->actorLabel, 0, 255 ) : null,
			'masterclass_id' => $this->masterclassId,
			'account_id'     => $this->accountId,
			'subject_label'  => null !== $this->subjectLabel ? mb_substr( $this->subjectLabel, 0, 255 ) : null,
			'actor_ip'       => $this->actorIp,
			'context'        => false === $context ? null : $context,
			'created_at'     => $this->createdAt,
		);
	}
}
