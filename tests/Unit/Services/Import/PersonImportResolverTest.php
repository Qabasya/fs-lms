<?php

declare( strict_types=1 );

namespace Unit\Services\Import;

use Inc\DTO\Person\PersonDTO;
use Inc\DTO\Person\PersonInputDTO;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Services\Import\PersonImportResolver;
use Inc\Services\Person\PersonService;
use Inc\Services\Security\PiiCryptoService;
use PHPUnit\Framework\TestCase;

class PersonImportResolverTest extends TestCase {

	private PersonService $personService;
	private PersonRepository $personRepo;
	private PiiCryptoService $crypto;
	private PersonImportResolver $resolver;

	protected function setUp(): void {
		parent::setUp();

		$this->personService = $this->createMock( PersonService::class );
		$this->personRepo    = $this->createMock( PersonRepository::class );
		$this->crypto        = $this->createMock( PiiCryptoService::class );
		$this->crypto->method( 'hash' )->willReturnCallback( static fn( string $v ): string => 'hash_' . $v );

		$this->resolver = new PersonImportResolver(
			$this->personService,
			$this->personRepo,
			$this->crypto,
		);
	}

	private function input( string $docNumber = '', ?string $email = null ): PersonInputDTO {
		return new PersonInputDTO(
			lastName:  'Иванов',
			firstName: 'Иван',
			docNumber: $docNumber,
			isStudent: true,
			birthDate: '2008-01-01',
			email:     $email,
		);
	}

	private function person( int $id ): PersonDTO {
		return new PersonDTO(
			id:         $id,
			wpUserId:   null,
			lastName:   'Иванов',
			firstName:  'Иван',
			middleName: null,
			birthDate:  '2008-01-01',
			isStudent:  true,
			school:     null,
			grade:      null,
			expelledAt: null,
			createdAt:  '2024-01-01 00:00:00',
			updatedAt:  '2024-01-01 00:00:00',
		);
	}

	public function testResolvesByDocNumberHash(): void {
		$this->personService->method( 'findByDocNumberHash' )->with( 'hash_1234' )->willReturn( 7 );
		$this->personService->expects( $this->never() )->method( 'findByEmailHash' );

		$this->assertSame( 7, $this->resolver->resolve( $this->input( docNumber: '1234' ) ) );
	}

	public function testFallsBackToEmailHash(): void {
		$this->personService->method( 'findByEmailHash' )->with( 'hash_a@b.c' )->willReturn( 9 );
		$this->personRepo->expects( $this->never() )->method( 'findByNameAndBirthDate' );

		$this->assertSame( 9, $this->resolver->resolve( $this->input( email: 'a@b.c' ) ) );
	}

	public function testFallsBackToNameAndBirthDate(): void {
		$this->personRepo->method( 'findByNameAndBirthDate' )->willReturn( $this->person( 3 ) );

		$this->assertSame( 3, $this->resolver->resolve( $this->input() ) );
	}

	public function testReturnsNullWhenNothingMatches(): void {
		$this->personRepo->method( 'findByNameAndBirthDate' )->willReturn( null );

		$this->assertNull( $this->resolver->resolve( $this->input() ) );
	}
}
