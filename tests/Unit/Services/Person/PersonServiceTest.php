<?php

declare(strict_types=1);

namespace Unit\Services\Person;

use Inc\Contracts\ClockInterface;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Person\PersonDocumentsDTO;
use Inc\DTO\Person\PersonDTO;
use Inc\DTO\Person\PersonInputDTO;
use Inc\Repositories\WPDBRepositories\PersonDocumentsRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Services\Person\PersonService;
use Inc\Services\Security\PiiCryptoService;
use PHPUnit\Framework\TestCase;

class PersonServiceTest extends TestCase {

	private PersonRepository $personRepo;
	private PersonDocumentsRepository $docsRepo;
	private PiiCryptoService $crypto;
	private PersonService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->personRepo = $this->createMock( PersonRepository::class );
		$this->docsRepo   = $this->createMock( PersonDocumentsRepository::class );
		$this->crypto     = $this->createMock( PiiCryptoService::class );
		$clock            = $this->createMock( ClockInterface::class );
		$clock->method( 'now' )->willReturn( '2024-01-01 00:00:00' );
		$logEvents = $this->createMock( LogEventDispatcherInterface::class );

		$this->service = new PersonService(
			$this->personRepo,
			$this->docsRepo,
			$this->crypto,
			$clock,
			$logEvents,
		);
	}

	// ── Helpers ─────────────────────────────────────────────────────────────────

	private function makeInput( string $docNumber = '1234567890' ): PersonInputDTO {
		return new PersonInputDTO(
			lastName:  'Иванов',
			firstName: 'Иван',
			docNumber: $docNumber,
			isStudent: true,
		);
	}

	private function makeDocsDTO( int $personId ): PersonDocumentsDTO {
		return new PersonDocumentsDTO(
			id:             1,
			personId:       $personId,
			emailEnc:       null,
			emailHash:      null,
			phoneEnc:       null,
			phoneHash:      null,
			docType:        'pass',
			docNumberEnc:   'enc_doc',
			docNumberHash:  'hash_doc',
			docIssuedByEnc: null,
			docIssuedDate:  null,
			innEnc:         null,
			innHash:        null,
			addressEnc:     null,
		);
	}

	private function makePersonDTO( int $id, ?string $expelledAt = null ): PersonDTO {
		return new PersonDTO(
			id:         $id,
			wpUserId:   null,
			lastName:   'Иванов',
			firstName:  'Иван',
			middleName: null,
			birthDate:  null,
			isStudent:  true,
			school:     null,
			grade:      null,
			expelledAt: $expelledAt,
			createdAt:  '2024-01-01 00:00:00',
			updatedAt:  '2024-01-01 00:00:00',
		);
	}

	// ── Tests ────────────────────────────────────────────────────────────────────

	public function test_create_or_find_by_returns_existing_person_id_on_doc_hash_match(): void {
		$personId = 42;
		$this->crypto->method( 'hash' )->willReturn( 'hash_doc' );
		$this->docsRepo->method( 'findByDocNumberHash' )->willReturn( $this->makeDocsDTO( $personId ) );
		$this->personRepo->method( 'findIncludingDeleted' )->willReturn( $this->makePersonDTO( $personId ) );

		$this->personRepo->expects( $this->never() )->method( 'create' );
		$this->docsRepo->expects( $this->never() )->method( 'create' );

		self::assertSame( $personId, $this->service->createOrFindBy( $this->makeInput() ) );
	}

	public function test_create_or_find_by_clears_expelled_at_for_expelled_person(): void {
		$personId = 42;
		$this->crypto->method( 'hash' )->willReturn( 'hash_doc' );
		$this->docsRepo->method( 'findByDocNumberHash' )->willReturn( $this->makeDocsDTO( $personId ) );
		$this->personRepo->method( 'findIncludingDeleted' )
			->willReturn( $this->makePersonDTO( $personId, '2024-01-01 00:00:00' ) );

		$this->personRepo->expects( $this->once() )
			->method( 'update' )
			->with( $personId, [ 'expelled_at' => null ] );

		self::assertSame( $personId, $this->service->createOrFindBy( $this->makeInput() ) );
	}

	public function test_create_or_find_by_creates_new_person_and_documents_when_not_found(): void {
		$newPersonId = 99;
		$this->crypto->method( 'hash' )->willReturn( 'hash_doc' );
		$this->crypto->method( 'encrypt' )->willReturn( 'enc_value' );
		$this->docsRepo->method( 'findByDocNumberHash' )->willReturn( null );
		$this->personRepo->method( 'create' )->willReturn( $newPersonId );

		$this->personRepo->expects( $this->once() )->method( 'create' );
		$this->docsRepo->expects( $this->once() )->method( 'create' );

		self::assertSame( $newPersonId, $this->service->createOrFindBy( $this->makeInput() ) );
	}
}
