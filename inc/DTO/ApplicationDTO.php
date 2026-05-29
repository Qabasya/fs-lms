<?php

declare( strict_types=1 );

namespace Inc\DTO;

use Inc\Enums\ApplicationStatus;

/**
 * Class ApplicationDTO
 *
 * Row-DTO строки таблицы fs_lms_applications.
 *
 * @package Inc\DTO
 *
 * ### Основные обязанности:
 *
 * 1. **Хранение данных заявки** — представляет запись из таблицы applications.
 * 2. **Преобразование массив <-> DTO** — методы fromArray() и toArray().
 *
 * ### Архитектурная роль:
 *
 * Используется в ApplicationRepository для передачи данных о заявке.
 * Поля *_enc хранятся как бинарные строки (BLOB) — не расшифровываются на уровне DTO.
 * Расшифровка выполняется только через ApplicationDecryptedDTO, создаваемый сервисами.
 *
 * ### Поля:
 *
 * - **studentDataEnc** — зашифрованные данные студента (PersonDataDTO)
 * - **parentDataEnc** — зашифрованные данные родителя (PersonDataDTO)
 * - **joinCodeHash** — хэш кода присоединения (для безопасности)
 * - **studentEmailHash** — хэш email студента (для поиска без расшифровки)
 */
readonly class ApplicationDTO {

	/**
	 * Конструктор DTO.
	 *
	 * @param int               $id                        ID заявки
	 * @param int|null          $studentPersonId           ID студента (из persons)
	 * @param int|null          $parentPersonId            ID родителя (из persons)
	 * @param string            $periodKey                 Ключ учебного периода
	 * @param ApplicationStatus $status                    Статус заявки
	 * @param string|null       $joinCodeHash              Хэш кода для присоединения
	 * @param string|null       $joinCodeExpiresAt         Срок действия кода
	 * @param string|null       $studentEmailHash          Хэш email студента
	 * @param string|null       $studentDataEnc            Зашифрованные данные студента
	 * @param string|null       $parentDataEnc             Зашифрованные данные родителя
	 * @param int|null          $convertedToEnrollmentId   ID зачисления (при успехе)
	 * @param string|null       $parentSubmittedIp         IP родителя при заполнении формы
	 * @param string|null       $parentSubmittedUa         User-Agent родителя
	 * @param int|null          $reviewedByUserId          ID модератора, проверившего заявку
	 * @param string|null       $rejectedReason            Причина отклонения
	 * @param string            $createdAt                 Дата создания
	 * @param string            $updatedAt                 Дата обновления
	 */
	public function __construct(
		public int $id,
		public ?int $studentPersonId,
		public ?int $parentPersonId,
		public string $periodKey,
		public ApplicationStatus $status,
		public ?string $joinCodeHash,
		public ?string $joinCodeExpiresAt,
		public ?string $studentEmailHash,
		public ?string $studentDataEnc,
		public ?string $parentDataEnc,
		public ?int $convertedToEnrollmentId,
		public ?string $parentSubmittedIp,
		public ?string $parentSubmittedUa,
		public ?int $reviewedByUserId,
		public ?string $rejectedReason,
		public string $createdAt,
		public string $updatedAt,
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
			id:                      (int) $row['id'],
			studentPersonId:         isset( $row['student_person_id'] ) ? (int) $row['student_person_id'] : null,
			parentPersonId:          isset( $row['parent_person_id'] ) ? (int) $row['parent_person_id'] : null,
			periodKey:               (string) $row['period_key'],
			status:                  ApplicationStatus::from( (string) $row['status'] ),
			joinCodeHash:            isset( $row['join_code_hash'] ) ? (string) $row['join_code_hash'] : null,
			joinCodeExpiresAt:       isset( $row['join_code_expires_at'] ) ? (string) $row['join_code_expires_at'] : null,
			studentEmailHash:        isset( $row['student_email_hash'] ) ? (string) $row['student_email_hash'] : null,
			studentDataEnc:          isset( $row['student_data_enc'] ) ? (string) $row['student_data_enc'] : null,
			parentDataEnc:           isset( $row['parent_data_enc'] ) ? (string) $row['parent_data_enc'] : null,
			convertedToEnrollmentId: isset( $row['converted_to_enrollment_id'] ) ? (int) $row['converted_to_enrollment_id'] : null,
			parentSubmittedIp:       isset( $row['parent_submitted_ip'] ) ? (string) $row['parent_submitted_ip'] : null,
			parentSubmittedUa:       isset( $row['parent_submitted_ua'] ) ? (string) $row['parent_submitted_ua'] : null,
			reviewedByUserId:        isset( $row['reviewed_by_user_id'] ) ? (int) $row['reviewed_by_user_id'] : null,
			rejectedReason:          isset( $row['rejected_reason'] ) ? (string) $row['rejected_reason'] : null,
			createdAt:               (string) $row['created_at'],
			updatedAt:               (string) $row['updated_at'],
		);
	}

	/**
	 * Преобразует DTO в массив для вставки/обновления в БД.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'id'                         => $this->id,
			'student_person_id'          => $this->studentPersonId,
			'parent_person_id'           => $this->parentPersonId,
			'period_key'                 => $this->periodKey,
			'status'                     => $this->status->value,
			'join_code_hash'             => $this->joinCodeHash,
			'join_code_expires_at'       => $this->joinCodeExpiresAt,
			'student_email_hash'         => $this->studentEmailHash,
			'student_data_enc'           => $this->studentDataEnc,
			'parent_data_enc'            => $this->parentDataEnc,
			'converted_to_enrollment_id' => $this->convertedToEnrollmentId,
			'parent_submitted_ip'        => $this->parentSubmittedIp,
			'parent_submitted_ua'        => $this->parentSubmittedUa,
			'reviewed_by_user_id'        => $this->reviewedByUserId,
			'rejected_reason'            => $this->rejectedReason,
			'created_at'                 => $this->createdAt,
			'updated_at'                 => $this->updatedAt,
		);
	}
}
