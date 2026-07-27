<?php

declare( strict_types=1 );

namespace Inc\DTO\Import;

/**
 * Результат импорта одной строки CSV.
 *
 * Возвращается из StudentRowImporter::import(). Ошибки строки
 * выражаются исключением (попадают в ImportReportDTO::errors),
 * успешные исходы — этим DTO со статусом created|skipped.
 */
readonly class ImportRowResultDTO {

	public const STATUS_CREATED = 'created';
	public const STATUS_SKIPPED = 'skipped';

	/**
	 * @param string                 $status      Один из STATUS_CREATED|STATUS_SKIPPED
	 * @param string|null            $note        Пояснение (например, причина пропуска)
	 * @param RowCredentialsDTO|null $credentials Созданные учётные данные (только режим Enrolled)
	 */
	public function __construct(
		public string             $status,
		public ?string            $note = null,
		public ?RowCredentialsDTO $credentials = null,
	) {}

	/**
	 * Запись успешно создана.
	 *
	 * @param string|null            $note        Необязательное пояснение
	 * @param RowCredentialsDTO|null $credentials Учётные данные строки (режим Enrolled)
	 *
	 * @return self
	 */
	public static function created( ?string $note = null, ?RowCredentialsDTO $credentials = null ): self {
		return new self( self::STATUS_CREATED, $note, $credentials );
	}

	/**
	 * Строка пропущена (дубль/уже существует).
	 *
	 * @param string|null $note Причина пропуска
	 *
	 * @return self
	 */
	public static function skipped( ?string $note = null ): self {
		return new self( self::STATUS_SKIPPED, $note );
	}

	/**
	 * @return bool true если запись создана
	 */
	public function isCreated(): bool {
		return self::STATUS_CREATED === $this->status;
	}
}
