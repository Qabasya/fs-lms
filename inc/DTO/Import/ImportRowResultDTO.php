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
	 * @param string|null            $label       Человекочитаемое описание строки (ФИО, группа,
	 *                                            договор) — для списка предпросмотра в dry-run
	 */
	public function __construct(
		public string             $status,
		public ?string            $note = null,
		public ?RowCredentialsDTO $credentials = null,
		public ?string            $label = null,
	) {}

	/**
	 * Запись успешно создана.
	 *
	 * @param string|null            $note        Необязательное пояснение
	 * @param RowCredentialsDTO|null $credentials Учётные данные строки (режим Enrolled)
	 * @param string|null            $label       Описание строки для предпросмотра
	 *
	 * @return self
	 */
	public static function created( ?string $note = null, ?RowCredentialsDTO $credentials = null, ?string $label = null ): self {
		return new self( self::STATUS_CREATED, $note, $credentials, $label );
	}

	/**
	 * Строка пропущена (дубль/уже существует).
	 *
	 * @param string|null $note  Причина пропуска
	 * @param string|null $label Описание строки для предпросмотра
	 *
	 * @return self
	 */
	public static function skipped( ?string $note = null, ?string $label = null ): self {
		return new self( self::STATUS_SKIPPED, $note, null, $label );
	}

	/**
	 * @return bool true если запись создана
	 */
	public function isCreated(): bool {
		return self::STATUS_CREATED === $this->status;
	}
}
