<?php

declare( strict_types=1 );

namespace Inc\DTO\Person;

/**
 * Class PiiAccessLogInputDTO
 *
 * Data Transfer Object для вставки записи в журнал доступа к персональным данным (pii_access_log).
 *
 * @package Inc\DTO\Person
 *
 * ### Основные обязанности:
 *
 * 1. **Типобезопасная передача данных** — инкапсулирует данные для записи доступа к PII.
 * 2. **Преобразование в массив** — метод toArray() для вставки в БД.
 *
 * ### Архитектурная роль:
 *
 * Используется в PiiAccessLogWriter для записи факта доступа к персональным данным
 * (с соблюдением требований 152-ФЗ и GDPR).
 *
 * ### Поля записи:
 *
 * - actorUserId — ID пользователя WordPress, запросившего доступ (null для анонимных действий)
 * - actorRole — роль пользователя на момент доступа (для аудита)
 * - personId — ID человека (из persons), чьи данные запрошены (null если не определён)
 * - fieldsAccessed — список запрошенных полей (через запятую: full_name, passport, inn)
 * - accessReason — причина доступа (обязательное поле для compliance)
 * - actorIp — IP-адрес пользователя
 * - actorUa — User-Agent браузера
 * - createdAt — дата и время доступа (MySQL datetime в UTC)
 *
 * ### Примечания:
 *
 * - Лог доступа к PII является неизменяемым (только append).
 * - Обязательность поля accessReason гарантирует, что каждый доступ к PII
 *   имеет легитимную причину.
 */
readonly class PiiAccessLogInputDTO {

	/**
	 * Конструктор DTO.
	 *
	 * @param int|null    $actorUserId    ID пользователя WP, запросившего доступ
	 * @param string|null $actorRole      Роль пользователя на момент доступа
	 * @param int|null    $personId       ID человека (из persons)
	 * @param string      $fieldsAccessed Список запрошенных полей (через запятую)
	 * @param string      $accessReason   Причина доступа
	 * @param string      $actorIp        IP-адрес пользователя
	 * @param string|null $actorUa        User-Agent браузера
	 * @param string      $createdAt      Дата и время доступа (MySQL datetime UTC)
	 */
	public function __construct(
		public ?int    $actorUserId,
		public ?string $actorRole,
		public ?int    $personId,
		public string  $fieldsAccessed,
		public string  $accessReason,
		public string  $actorIp,
		public ?string $actorUa,
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
			'actor_ua'        => $this->actorUa,
			'created_at'      => $this->createdAt,
		);
	}
}