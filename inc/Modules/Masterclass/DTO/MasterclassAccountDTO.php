<?php

declare( strict_types=1 );

namespace Inc\Modules\Masterclass\DTO;

use Inc\Modules\Masterclass\Enums\AccountDeleteReason;
use Inc\Modules\Masterclass\Enums\MasterclassAccountState;

/**
 * Временный аккаунт мастер-класса (строка `fs_lms_masterclass_accounts`).
 *
 * `id` — номер N (`student-N`, `Ученик#N`): строка не удаляется, номер не повторяется. Удалённый аккаунт
 * остаётся в выборках (`deletedAt`), вызывающий проверяет {@see self::isDeleted()}. Все даты — UTC.
 *
 * @package Inc\Modules\Masterclass\DTO
 */
readonly class MasterclassAccountDTO {

	/**
	 * @param int                     $id              Номер N
	 * @param int                     $masterclassId   Мастер-класс
	 * @param int|null                $userId          Пользователь WP (null — учётка не создалась или создаётся)
	 * @param int|null                $personId        Физлицо
	 * @param int|null                $studentRecordId Запись об обучении (null — отвязана)
	 * @param MasterclassAccountState $state           Состояние
	 * @param string|null             $firstLoginAt    Первый вход
	 * @param string|null             $homeAt          Первый вход «дома»
	 * @param string|null             $contactAt       Первое нажатие «Связаться»
	 * @param int|null                $applicationId   Привязанная заявка
	 * @param string|null             $deletedAt       Мягкое удаление
	 * @param AccountDeleteReason|null $deleteReason   Причина удаления
	 * @param string                  $createdAt       Создан (UTC)
	 * @param string                  $updatedAt       Изменён (UTC)
	 */
	public function __construct(
		public int $id,
		public int $masterclassId,
		public ?int $userId,
		public ?int $personId,
		public ?int $studentRecordId,
		public MasterclassAccountState $state,
		public ?string $firstLoginAt,
		public ?string $homeAt,
		public ?string $contactAt,
		public ?int $applicationId,
		public ?string $deletedAt,
		public ?AccountDeleteReason $deleteReason,
		public string $createdAt,
		public string $updatedAt,
	) {}

	/**
	 * Неизвестное состояние в БД читается как `Active`; неизвестная причина — как отсутствующая.
	 * Состояние пишет только модуль, поэтому такого не бывает; падать на выдаче списка из-за испорченной
	 * строки хуже, чем показать её.
	 *
	 * @param array<string, mixed> $row Строка таблицы
	 */
	public static function fromArray( array $row ): self {
		return new self(
			id:              (int) $row['id'],
			masterclassId:   (int) ( $row['masterclass_id'] ?? 0 ),
			userId:          self::nullableInt( $row['user_id'] ?? null ),
			personId:        self::nullableInt( $row['person_id'] ?? null ),
			studentRecordId: self::nullableInt( $row['student_record_id'] ?? null ),
			state:           MasterclassAccountState::tryFrom( (string) ( $row['state'] ?? '' ) ) ?? MasterclassAccountState::Active,
			firstLoginAt:    self::nullableString( $row['first_login_at'] ?? null ),
			homeAt:          self::nullableString( $row['home_at'] ?? null ),
			contactAt:       self::nullableString( $row['contact_at'] ?? null ),
			applicationId:   self::nullableInt( $row['application_id'] ?? null ),
			deletedAt:       self::nullableString( $row['deleted_at'] ?? null ),
			deleteReason:    AccountDeleteReason::tryFrom( (string) ( $row['delete_reason'] ?? '' ) ),
			createdAt:       (string) ( $row['created_at'] ?? '' ),
			updatedAt:       (string) ( $row['updated_at'] ?? '' ),
		);
	}

	/** Номер аккаунта (`N` в `student-N` и `Ученик#N`). */
	public function number(): int {
		return $this->id;
	}

	public function isDeleted(): bool {
		return null !== $this->deletedAt;
	}

	private static function nullableInt( mixed $value ): ?int {
		return null === $value || '' === $value ? null : (int) $value;
	}

	private static function nullableString( mixed $value ): ?string {
		return null === $value || '' === $value ? null : (string) $value;
	}
}
