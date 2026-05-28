<?php

declare( strict_types=1 );

namespace Inc\DTO;

/**
 * Class ConsentDTO
 *
 * Row-DTO строки таблицы fs_lms_consents.
 *
 * @package Inc\DTO
 *
 * ### Основные обязанности:
 *
 * 1. **Хранение записи согласия** — представляет запись из таблицы consents.
 * 2. **Преобразование массив <-> DTO** — методы fromArray() и toArray().
 *
 * ### Архитектурная роль:
 *
 * Используется в ConsentRepository для передачи данных о юридических согласиях
 * на обработку персональных данных.
 *
 * ### Примечания:
 *
 * - document_hash — хэш подписанного документа (для юридической значимости)
 * - valid_until — срок действия согласия (NULL — бессрочно)
 * - signed_for_person_id — ID человека, за которого подписано согласие
 *   (используется для подписания родителем за ребёнка)
 */
readonly class ConsentDTO {

	/**
	 * Конструктор DTO.
	 *
	 * @param int         $id                ID записи согласия
	 * @param int|null    $applicationId     ID заявки, в рамках которой подписано согласие
	 * @param int|null    $personId          ID человека, подписавшего согласие
	 * @param string      $subjectRole       Роль субъекта (student, parent)
	 * @param string      $consentType       Тип согласия (pd_processing, marketing и т.д.)
	 * @param string      $version           Версия согласия (v1, v2)
	 * @param string      $documentHash      Хэш подписанного документа (PDF)
	 * @param string      $ipAddress         IP-адрес подписавшего
	 * @param string      $userAgent         User-Agent браузера
	 * @param string      $acceptedAt        Дата и время подписания
	 * @param string|null $validUntil        Срок действия согласия (NULL — бессрочно)
	 * @param string|null $withdrawnAt       Дата отзыва согласия
	 * @param string|null $withdrawnReason   Причина отзыва
	 * @param int|null    $signedForPersonId ID человека, за которого подписано согласие
	 */
	public function __construct(
		public int     $id,
		public ?int    $applicationId,
		public ?int    $personId,
		public string  $subjectRole,
		public string  $consentType,
		public string  $version,
		public string  $documentHash,
		public string  $ipAddress,
		public string  $userAgent,
		public string  $acceptedAt,
		public ?string $validUntil,
		public ?string $withdrawnAt,
		public ?string $withdrawnReason,
		public ?int    $signedForPersonId,
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
			id:                (int) $row['id'],
			applicationId:     isset( $row['application_id'] ) ? (int) $row['application_id'] : null,
			personId:          isset( $row['person_id'] ) ? (int) $row['person_id'] : null,
			subjectRole:       (string) $row['subject_role'],
			consentType:       (string) $row['consent_type'],
			version:           (string) $row['version'],
			documentHash:      (string) ( $row['document_hash'] ?? '' ),
			ipAddress:         (string) $row['ip_address'],
			userAgent:         (string) $row['user_agent'],
			acceptedAt:        (string) $row['accepted_at'],
			validUntil:        isset( $row['valid_until'] ) ? (string) $row['valid_until'] : null,
			withdrawnAt:       isset( $row['withdrawn_at'] ) ? (string) $row['withdrawn_at'] : null,
			withdrawnReason:   isset( $row['withdrawn_reason'] ) ? (string) $row['withdrawn_reason'] : null,
			signedForPersonId: isset( $row['signed_for_person_id'] ) ? (int) $row['signed_for_person_id'] : null,
		);
	}

	/**
	 * Преобразует DTO в массив для вставки/обновления в БД.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'id'                  => $this->id,
			'application_id'      => $this->applicationId,
			'person_id'           => $this->personId,
			'subject_role'        => $this->subjectRole,
			'consent_type'        => $this->consentType,
			'version'             => $this->version,
			'document_hash'       => $this->documentHash,
			'ip_address'          => $this->ipAddress,
			'user_agent'          => $this->userAgent,
			'accepted_at'         => $this->acceptedAt,
			'valid_until'         => $this->validUntil,
			'withdrawn_at'        => $this->withdrawnAt,
			'withdrawn_reason'    => $this->withdrawnReason,
			'signed_for_person_id' => $this->signedForPersonId,
		);
	}
}