<?php

declare( strict_types=1 );

namespace Inc\DTO\Person;

/**
 * Class PersonDocumentsDTO
 *
 * Data Transfer Object для работы с документами и контактами лица (PersonDocuments).
 *
 * @package Inc\DTO\Person
 *
 * ### Основные обязанности:
 *
 * 1. **Хранение персональных данных** — документы, контакты, адрес в зашифрованном виде.
 * 2. **Преобразование массива в DTO** — статический метод fromArray() для результата SQL-запроса.
 *
 * ### Архитектурная роль:
 *
 * Используется в PersonDocumentsRepository для передачи данных
 * о документах и контактной информации лица (email, телефон, паспорт, ИНН, адрес).
 *
 * ### Поля записи:
 *
 * - **emailEnc/phoneEnc/addressEnc** — зашифрованные данные (BLOB)
 * - **emailHash/phoneHash/docNumberHash/innHash** — хеши для поиска
 * - **docType** — тип документа (pass, birth_certificate)
 * - **docNumberEnc** — зашифрованный номер документа
 * - **docIssuedByEnc** — зашифрованное наименование органа, выдавшего документ
 * - **docIssuedDate** — дата выдачи документа (не зашифрована)
 *
 * ### Примечания:
 *
 * - Данные хранятся в отдельной таблице fs_lms_person_documents,
 *   что позволяет связывать одного человека с несколькими документами.
 * - Хеши используются для поиска дубликатов без расшифровки.
 */
readonly class PersonDocumentsDTO {

	/**
	 * Конструктор DTO.
	 *
	 * @param int         $id               ID записи
	 * @param int         $personId         ID лица (из persons)
	 * @param string|null $emailEnc         Зашифрованный email
	 * @param string|null $emailHash        Хеш email (для поиска)
	 * @param string|null $phoneEnc         Зашифрованный телефон
	 * @param string|null $phoneHash        Хеш телефона (для поиска)
	 * @param string|null $docType          Тип документа (pass, birth_certificate)
	 * @param string|null $docNumberEnc     Зашифрованный номер документа
	 * @param string|null $docNumberHash    Хеш номера документа (для поиска)
	 * @param string|null $docIssuedByEnc   Зашифрованное наименование органа выдачи
	 * @param string|null $docIssuedDate    Дата выдачи документа (Y-m-d)
	 * @param string|null $innEnc           Зашифрованный ИНН
	 * @param string|null $innHash          Хеш ИНН (для поиска)
	 * @param string|null $addressEnc       Зашифрованный адрес
	 */
	public function __construct(
		public int     $id,
		public int     $personId,
		public ?string $emailEnc,
		public ?string $emailHash,
		public ?string $phoneEnc,
		public ?string $phoneHash,
		public ?string $docType,
		public ?string $docNumberEnc,
		public ?string $docNumberHash,
		public ?string $docIssuedByEnc,
		public ?string $docIssuedDate,
		public ?string $innEnc,
		public ?string $innHash,
		public ?string $addressEnc,
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
			id:             (int) $row['id'],
			personId:       (int) $row['person_id'],
			emailEnc:       isset( $row['email_enc'] ) ? (string) $row['email_enc'] : null,
			emailHash:      isset( $row['email_hash'] ) ? (string) $row['email_hash'] : null,
			phoneEnc:       isset( $row['phone_enc'] ) ? (string) $row['phone_enc'] : null,
			phoneHash:      isset( $row['phone_hash'] ) ? (string) $row['phone_hash'] : null,
			docType:        isset( $row['doc_type'] ) ? (string) $row['doc_type'] : null,
			docNumberEnc:   isset( $row['doc_number_enc'] ) ? (string) $row['doc_number_enc'] : null,
			docNumberHash:  isset( $row['doc_number_hash'] ) ? (string) $row['doc_number_hash'] : null,
			docIssuedByEnc: isset( $row['doc_issued_by_enc'] ) ? (string) $row['doc_issued_by_enc'] : null,
			docIssuedDate:  isset( $row['doc_issued_date'] ) ? (string) $row['doc_issued_date'] : null,
			innEnc:         isset( $row['inn_enc'] ) ? (string) $row['inn_enc'] : null,
			innHash:        isset( $row['inn_hash'] ) ? (string) $row['inn_hash'] : null,
			addressEnc:     isset( $row['address_enc'] ) ? (string) $row['address_enc'] : null,
		);
	}
}