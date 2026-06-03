<?php

declare( strict_types=1 );

namespace Inc\DTO;

use Inc\Enums\EnrollmentStatus;

/**
 * Class EnrollmentDTO
 *
 * Row-DTO строки таблицы fs_lms_enrollments.
 *
 * @package Inc\DTO
 *
 * ### Основные обязанности:
 *
 * 1. **Хранение данных зачисления** — представляет запись из таблицы enrollments.
 * 2. **Преобразование массив <-> DTO** — методы fromArray() и toArray().
 *
 * ### Архитектурная роль:
 *
 * Используется в EnrollmentRepository для передачи данных о зачислении студента
 * на предмет/группу. Связан с заявкой через source_application_id.
 *
 * ### Примечания:
 *
 * - snapshot_enc — зашифрованный слепок (snapshot) всех данных на момент зачисления
 *   (ученик, родитель, договор, приказ). Хранится как BLOB.
 * - Расшифровывается в EnrollmentDecryptedDTO сервисным слоем.
 * - status — статус зачисления (active, finished, expelled, transferred)
 * - terminated_* поля заполняются при завершении/отчислении/переводе
 */
readonly class EnrollmentDTO {

	/**
	 * Конструктор DTO.
	 *
	 * @param int               $id                  ID зачисления
	 * @param int               $studentPersonId     ID студента (из persons)
	 * @param int|null          $sourceApplicationId ID заявки, на основе которой создано зачисление
	 * @param string|null       $groupId             ID группы (если зачислен в группу)
	 * @param string            $subjectKey          Ключ предмета
	 * @param string            $periodKey           Ключ учебного периода
	 * @param EnrollmentStatus  $status              Статус зачисления
	 * @param string            $enrolledAt          Дата зачисления
	 * @param string|null       $terminatedAt        Дата завершения обучения
	 * @param string|null       $terminatedReason    Причина завершения/отчисления
	 * @param int|null          $terminatedByUserId  ID пользователя, завершившего обучение
	 * @param string|null       $snapshotEnc         Зашифрованный слепок данных (BLOB)
	 * @param string            $createdAt           Дата создания
	 * @param string            $updatedAt           Дата обновления
	 */
	public function __construct(
		public int $id,
		public int $studentPersonId,
		public ?int $sourceApplicationId,
		public ?string $groupId,
		public string $subjectKey,
		public string $periodKey,
		public EnrollmentStatus $status,
		public string $enrolledAt,
		public ?string $terminatedAt,
		public ?string $terminatedReason,
		public ?int $terminatedByUserId,
		public ?string $snapshotEnc,
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
			id:                  (int) $row['id'],
			studentPersonId:     (int) $row['student_person_id'],
			sourceApplicationId: isset( $row['source_application_id'] ) ? (int) $row['source_application_id'] : null,
			groupId:             isset( $row['group_id'] ) && $row['group_id'] !== '' ? (string) $row['group_id'] : null,
			subjectKey:          (string) $row['subject_key'],
			periodKey:           (string) $row['period_key'],
			status:              EnrollmentStatus::from( (string) $row['status'] ),
			enrolledAt:          (string) $row['enrolled_at'],
			terminatedAt:        isset( $row['terminated_at'] ) ? (string) $row['terminated_at'] : null,
			terminatedReason:    isset( $row['terminated_reason'] ) ? (string) $row['terminated_reason'] : null,
			terminatedByUserId:  isset( $row['terminated_by_user_id'] ) ? (int) $row['terminated_by_user_id'] : null,
			snapshotEnc:         isset( $row['snapshot_enc'] ) ? (string) $row['snapshot_enc'] : null,
			createdAt:           (string) $row['created_at'],
			updatedAt:           (string) $row['updated_at'],
		);
	}

	/**
	 * Преобразует DTO в массив для вставки/обновления в БД.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'id'                    => $this->id,
			'student_person_id'     => $this->studentPersonId,
			'source_application_id' => $this->sourceApplicationId,
			'group_id'              => $this->groupId,
			'subject_key'           => $this->subjectKey,
			'period_key'            => $this->periodKey,
			'status'                => $this->status->value,
			'enrolled_at'           => $this->enrolledAt,
			'terminated_at'         => $this->terminatedAt,
			'terminated_reason'     => $this->terminatedReason,
			'terminated_by_user_id' => $this->terminatedByUserId,
			'snapshot_enc'          => $this->snapshotEnc,
			'created_at'            => $this->createdAt,
			'updated_at'            => $this->updatedAt,
		);
	}
}