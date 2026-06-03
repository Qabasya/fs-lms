<?php

declare( strict_types=1 );

namespace Inc\DTO;

/**
 * Class PiiAccessLogInputDTO
 *
 * Входные данные для создания записи журнала доступа к персональным данным.
 *
 * @package Inc\DTO
 */
readonly class PiiAccessLogInputDTO {

	/**
	 * Конструктор DTO.
	 *
	 * @param int|null    $actorUserId    ID пользователя WP, запросившего доступ (null — анонимный)
	 * @param string|null $actorRole      Роль пользователя на момент доступа
	 * @param int|null    $personId       ID человека (из persons), чьи данные запрошены (null — не определён)
	 * @param string      $fieldsAccessed Список запрошенных полей (через запятую)
	 * @param string      $accessReason   Причина доступа
	 * @param string      $actorIp        IP-адрес пользователя
	 * @param string      $createdAt      Дата и время доступа (mysql UTC)
	 */
	public function __construct(
		public ?int    $actorUserId,
		public ?string $actorRole,
		public ?int    $personId,
		public string  $fieldsAccessed,
		public string  $accessReason,
		public string  $actorIp,
		public string  $createdAt,
	) {}

	/**
	 * Преобразует DTO в массив для вставки в БД.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'actor_user_id'   => $this->actorUserId,
			'actor_role'      => $this->actorRole,
			'person_id'       => $this->personId,
			'fields_accessed' => $this->fieldsAccessed,
			'access_reason'   => $this->accessReason,
			'actor_ip'        => $this->actorIp,
			'created_at'      => $this->createdAt,
		);
	}
}
