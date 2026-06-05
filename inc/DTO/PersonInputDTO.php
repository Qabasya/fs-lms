<?php

declare( strict_types=1 );

namespace Inc\DTO;

readonly class PersonInputDTO {

	public function __construct(
		public string  $fullName,
		public string  $docNumber,
		public string  $role       = 'student',
		public string  $docType    = '',
		public string  $birthDate  = '',
		public string  $inn        = '',
		public string  $address    = '',
		public string  $phone      = '',
		public ?string $email      = null,
		public ?int    $wpUserId   = null,
	) {}

	public function toRawData(): array {
		$data = array(
			'full_name'  => $this->fullName,
			'doc_number' => $this->docNumber,
			'role'       => $this->role,
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
