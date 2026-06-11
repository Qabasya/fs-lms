<?php

declare( strict_types=1 );

namespace Inc\DTO\Person;

/**
 * Class PersonInputDTO
 *
 * Data Transfer Object для передачи данных при создании или обновлении лица (Person).
 *
 * @package Inc\DTO\Person
 *
 * ### Основные обязанности:
 *
 * 1. **Типобезопасная передача данных** — инкапсулирует все поля для создания/обновления лица.
 * 2. **Формирование полного имени** — метод fullName().
 * 3. **Преобразование в массив** — метод toRawData() для вставки в БД.
 *
 * ### Архитектурная роль:
 *
 * Используется в PersonService для создания новых лиц или поиска существующих.
 * Содержит все поля, необходимые для создания записи в таблице persons
 * и связанной таблице person_documents.
 *
 * ### Поля:
 *
 * - lastName, firstName, middleName — ФИО
 * - docNumber — номер документа (обязательное поле для поиска дубликатов)
 * - docType — тип документа (pass, birth_certificate)
 * - birthDate — дата рождения (Y-m-d)
 * - inn — ИНН
 * - address — адрес регистрации/проживания
 * - phone — номер телефона
 * - email — email (опционально)
 * - school — школа (для учеников)
 * - grade — класс (для учеников)
 * - docIssuedBy — кем выдан документ
 * - docIssuedDate — дата выдачи документа
 * - isStudent — флаг: true — ученик, false — родитель/представитель
 * - wpUserId — ID пользователя WordPress (если уже привязан)
 */
readonly class PersonInputDTO {

	/**
	 * Конструктор DTO.
	 *
	 * @param string      $lastName       Фамилия
	 * @param string      $firstName      Имя
	 * @param string      $docNumber      Номер документа (для поиска дубликатов)
	 * @param bool        $isStudent      Флаг: true — ученик, false — родитель/представитель
	 * @param string      $middleName     Отчество (опционально)
	 * @param string      $docType        Тип документа (pass, birth_certificate)
	 * @param string      $birthDate      Дата рождения (Y-m-d)
	 * @param string      $inn            ИНН
	 * @param string      $address        Адрес регистрации/проживания
	 * @param string      $phone          Номер телефона
	 * @param string      $school         Школа (для учеников)
	 * @param string      $grade          Класс (для учеников)
	 * @param string      $docIssuedBy    Кем выдан документ
	 * @param string      $docIssuedDate  Дата выдачи документа (Y-m-d)
	 * @param string|null $email          Email (опционально)
	 * @param int|null    $wpUserId       ID пользователя WP (если уже привязан)
	 */
	public function __construct(
		public string  $lastName,
		public string  $firstName,
		public string  $docNumber,
		public bool    $isStudent   = true,
		public string  $middleName  = '',
		public string  $docType     = '',
		public string  $birthDate   = '',
		public string  $inn         = '',
		public string  $address     = '',
		public string  $phone       = '',
		public string  $school       = '',
		public string  $grade        = '',
		public string  $docIssuedBy  = '',
		public string  $docIssuedDate = '',
		public ?string $email        = null,
		public ?int    $wpUserId     = null,
	) {}

	/**
	 * Возвращает полное имя в формате "Фамилия Имя Отчество".
	 *
	 * @return string
	 */
	public function fullName(): string {
		return trim( "{$this->lastName} {$this->firstName} {$this->middleName}" );
	}

	/**
	 * Преобразует DTO в массив для вставки/обновления в БД.
	 * Пустые строки преобразуются в null (кроме обязательных полей).
	 *
	 * @return array<string, mixed>
	 */
	public function toRawData(): array {
		$data = array(
			'last_name'   => $this->lastName,
			'first_name'  => $this->firstName,
			'middle_name' => $this->middleName !== '' ? $this->middleName : null,
			'doc_number'  => $this->docNumber,
			'is_student'  => $this->isStudent,
		);

		// Добавляем необязательные поля только если они не пустые
		if ( '' !== $this->docType )   { $data['doc_type']   = $this->docType; }
		if ( '' !== $this->birthDate ) { $data['birth_date'] = $this->birthDate; }
		if ( '' !== $this->inn )       { $data['inn']        = $this->inn; }
		if ( '' !== $this->address )   { $data['address']    = $this->address; }
		if ( '' !== $this->phone )     { $data['phone']      = $this->phone; }
		if ( '' !== $this->docIssuedBy )  { $data['doc_issued_by']  = $this->docIssuedBy; }
		if ( '' !== $this->docIssuedDate ) { $data['doc_issued_date'] = $this->docIssuedDate; }
		if ( null !== $this->email )   { $data['email'] = $this->email; }
		if ( null !== $this->wpUserId ) { $data['wp_user_id'] = $this->wpUserId; }

		return $data;
	}
}