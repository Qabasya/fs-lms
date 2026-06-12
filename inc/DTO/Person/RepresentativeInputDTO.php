<?php

declare( strict_types=1 );

namespace Inc\DTO\Person;

readonly class RepresentativeInputDTO {

	public function __construct(
		public int    $studentPersonId,
		public string $lastName,
		public string $firstName,
		public string $middleName,
		public string $birthDate,
		public string $docType,
		public string $docNumber,
		public string $docIssuedBy,
		public string $docIssuedDate,
		public string $inn,
		public string $address,
		public string $phone,
		public string $email,
	) {}

	public function fullName(): string {
		return trim( "{$this->lastName} {$this->firstName} {$this->middleName}" );
	}
}
