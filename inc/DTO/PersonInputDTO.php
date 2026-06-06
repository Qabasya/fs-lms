<?php

declare( strict_types=1 );

namespace Inc\DTO;

readonly class PersonInputDTO {

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
		public ?string $email       = null,
		public ?int    $wpUserId    = null,
	) {}

	public function fullName(): string {
		return trim( "{$this->lastName} {$this->firstName} {$this->middleName}" );
	}

	public function toRawData(): array {
		$data = array(
			'last_name'   => $this->lastName,
			'first_name'  => $this->firstName,
			'middle_name' => $this->middleName !== '' ? $this->middleName : null,
			'doc_number'  => $this->docNumber,
			'is_student'  => $this->isStudent,
		);

		if ( '' !== $this->docType )   { $data['doc_type']   = $this->docType; }
		if ( '' !== $this->birthDate ) { $data['birth_date'] = $this->birthDate; }
		if ( '' !== $this->inn )       { $data['inn']        = $this->inn; }
		if ( '' !== $this->address )   { $data['address']    = $this->address; }
		if ( '' !== $this->phone )     { $data['phone']      = $this->phone; }
		if ( null !== $this->email )   { $data['email']      = $this->email; }
		if ( null !== $this->wpUserId ) { $data['wp_user_id'] = $this->wpUserId; }

		return $data;
	}
}
