<?php

declare( strict_types=1 );

namespace Unit\Services\Import;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Import\ImportContextDTO;
use Inc\DTO\Person\PersonInputDTO;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Services\Import\PersonImportResolver;
use Inc\Services\Import\StudentRecordWriter;
use Inc\Services\Person\PersonService;
use PHPUnit\Framework\TestCase;

class StudentRecordWriterTest extends TestCase {

	private GroupsRepository $groups;
	private PersonImportResolver $personResolver;
	private PersonService $personService;
	private ClockInterface $clock;
	private StudentRecordWriter $writer;

	protected function setUp(): void {
		parent::setUp();

		$this->groups         = $this->createMock( GroupsRepository::class );
		$this->personResolver = $this->createMock( PersonImportResolver::class );
		$this->personService  = $this->createMock( PersonService::class );
		$this->clock          = $this->createMock( ClockInterface::class );
		$this->clock->method( 'now' )->willReturn( '2024-01-01 00:00:00' );

		$this->writer = new StudentRecordWriter(
			$this->groups,
			$this->personResolver,
			$this->personService,
			$this->clock,
		);
	}

	private function ctx(): ImportContextDTO {
		return new ImportContextDTO( 'math', '2024', false, 1, 1 );
	}

	private function personInput(): PersonInputDTO {
		return new PersonInputDTO( lastName: 'Иванов', firstName: 'Иван', docNumber: '' );
	}

	public function testResolveGroupIdReturnsNullWhenNotFound(): void {
		$this->groups->method( 'findByNameSubjectPeriod' )->willReturn( null );

		$this->assertNull( $this->writer->resolveGroupId( 'G-1', $this->ctx() ) );
	}

	public function testResolveGroupIdReturnsExistingId(): void {
		$this->groups->method( 'findByNameSubjectPeriod' )
			->with( 'G-1', 'math', '2024' )
			->willReturn( (object) array( 'id' => 50 ) );

		$this->assertSame( 50, $this->writer->resolveGroupId( 'G-1', $this->ctx() ) );
	}

	public function testResolvePersonIdDelegatesToResolver(): void {
		$input = $this->personInput();
		$this->personResolver->method( 'resolve' )->with( $input )->willReturn( 42 );

		$this->assertSame( 42, $this->writer->resolvePersonId( $input ) );
	}

	public function testCreateGroupPassesSubjectAndPeriodFromContext(): void {
		$this->groups->expects( $this->once() )->method( 'create' )
			->with( $this->callback( function ( array $data ): bool {
				return 'G-1' === $data['name']
					&& 'math' === $data['subject_key']
					&& '2024' === $data['academic_period_id']
					&& null === $data['teacher_id']
					&& null === $data['meetings'];
			} ) )
			->willReturn( 50 );

		$this->assertSame( 50, $this->writer->createGroup( 'G-1', $this->ctx() ) );
	}

	public function testCreatePersonDelegatesToPersonService(): void {
		$input = $this->personInput();
		$this->personService->method( 'createOrFindBy' )->with( $input )->willReturn( 202 );

		$this->assertSame( 202, $this->writer->createPerson( $input ) );
	}
}
