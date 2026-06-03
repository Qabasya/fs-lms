<?php

declare( strict_types=1 );

namespace Inc\DTO;

/**
 * Class PersonInputDTO
 *
 * Входные данные для создания или поиска записи физического лица в PersonService.
 *
 * @package Inc\DTO
 */
readonly class PersonInputDTO {

	/**
	 * Конструктор DTO.
	 *
	 * @param string      $fullName   Полное имя (ФИО)
	 * @param string      $docNumber  Номер документа (обязателен для createOrFindBy)
	 * @param string      $docType    Тип документа (pass, birth_certificate и т.д.)
	 * @param string      $birthDate  Дата рождения (Y-m-d)
	 * @param string      $inn        ИНН
	 * @param string      $address    Адрес регистрации
	 * @param string      $phone      Номер телефона
	 * @param string|null $email      Email (null если не указан)
	 * @param int|null    $wpUserId   ID пользователя WP (null если не привязан)
	 */
	public function __construct(
		public string  $fullName,
		public string  $docNumber,
		public string  $docType    = '',
		public string  $birthDate  = '',
		public string  $inn        = '',
		public string  $address    = '',
		public string  $phone      = '',
		public ?string $email      = null,
		public ?int    $wpUserId   = null,
	) {}

	/**
	 * Преобразует DTO в rawData-формат для PersonService::createOrFindBy().
	 *
	 * @return array<string, mixed>
	 */
	public function toRawData(): array {
		$data = array(
			'full_name'  => $this->fullName,
			'doc_number' => $this->docNumber,
		);

		if ( '' !== $this->docType ) {
			$data['doc_type'] = $this->docType;
		}
		if ( '' !== $this->birthDate ) {
			$data['birth_date'] = $this->birthDate;
		}
		if ( '' !== $this->inn ) {
			$data['inn'] = $this->inn;
		}
		if ( '' !== $this->address ) {
			$data['address'] = $this->address;
		}
		if ( '' !== $this->phone ) {
			$data['phone'] = $this->phone;
		}
		if ( null !== $this->email ) {
			$data['email'] = $this->email;
		}
		if ( null !== $this->wpUserId ) {
			$data['wp_user_id'] = $this->wpUserId;
		}

		return $data;
	}
}
