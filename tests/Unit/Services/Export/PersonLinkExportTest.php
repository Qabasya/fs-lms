<?php

declare( strict_types=1 );

namespace Unit\Services\Export;

use Inc\DTO\Enrollment\StudentRecordDTO;
use Inc\DTO\Person\PersonDocumentsDTO;
use Inc\DTO\Person\PersonDTO;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\OptionsRepositories\UserRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\PersonDocumentsRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Export\ParentsExportProvider;
use Inc\Services\Export\StudentsExportProvider;
use Inc\Services\Security\PiiCryptoService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Связь ученика и родителя в выгрузках (ключи для ВПР) и блок «документы и ИНН»
 * в экспорте родителей: по умолчанию выключен, по галочке — родитель и его дети.
 */
class PersonLinkExportTest extends TestCase {

	private PersonRepository&MockObject $persons;
	private StudentRecordRepository&MockObject $records;
	private PersonDocumentsRepository&MockObject $docs;
	private PiiCryptoService&MockObject $crypto;

	protected function setUp(): void {
		parent::setUp();
		$this->persons = $this->createMock( PersonRepository::class );
		$this->records = $this->createMock( StudentRecordRepository::class );
		$this->docs    = $this->createMock( PersonDocumentsRepository::class );
		$this->crypto  = $this->createMock( PiiCryptoService::class );
		$this->crypto->method( 'decrypt' )->willReturnCallback( static fn( string $b ): string => 'dec:' . $b );
	}

	private function person( int $id, bool $isStudent, string $last, ?string $birth = null ): PersonDTO {
		return PersonDTO::fromArray( array(
			'id' => $id, 'last_name' => $last, 'first_name' => 'И', 'is_student' => $isStudent,
			'birth_date' => $birth, 'created_at' => '2024-01-01', 'updated_at' => '2024-01-01',
		) );
	}

	private function record( int $student, int $parent ): StudentRecordDTO {
		return StudentRecordDTO::fromArray( array(
			'id' => $student * 10 + $parent, 'student_person_id' => $student, 'parent_person_id' => $parent, 'group_id' => 0,
		) );
	}

	private function students(): StudentsExportProvider {
		return new StudentsExportProvider(
			$this->persons, $this->records, $this->docs, $this->createMock( GroupsRepository::class ),
			$this->createMock( SubjectRepository::class ), $this->createMock( UserRepository::class ), $this->crypto
		);
	}

	private function parents(): ParentsExportProvider {
		return new ParentsExportProvider(
			$this->persons, $this->records, $this->docs, $this->createMock( GroupsRepository::class ),
			$this->createMock( SubjectRepository::class ), $this->createMock( UserRepository::class ), $this->crypto
		);
	}

	/** @return array<string, mixed> заголовок => значение для первой строки */
	private function firstRow( $provider, array $context ): array {
		$row = iterator_to_array( $provider->rows( $context ), false )[0];
		$out = array();
		foreach ( $provider->columns( $context ) as $col ) {
			$out[ $col->header ] = ( $col->extractor )( $row );
		}

		return $out;
	}

	public function test_student_row_carries_parent_id(): void {
		$this->persons->method( 'find' )->willReturn( $this->person( 5, true, 'Петров' ) );
		$this->records->method( 'findActiveByStudent' )->willReturn( array( $this->record( 5, 9 ), $this->record( 5, 9 ) ) );
		$this->docs->method( 'findByPersonId' )->willReturn( null );

		$row = $this->firstRow( $this->students(), array( 'ids' => array( 5 ) ) );

		self::assertSame( 5, $row['ID ученика'] );
		self::assertSame( '9', $row['ID родителя'] );
	}

	public function test_parent_row_carries_student_ids_without_documents_by_default(): void {
		$this->persons->method( 'find' )->willReturnCallback( fn( int $id ) => match ( $id ) {
			9 => $this->person( 9, false, 'Петрова' ),
			5 => $this->person( 5, true, 'Петров' ),
			6 => $this->person( 6, true, 'Петрова-мл' ),
		} );
		$this->records->method( 'findAllByParent' )->willReturn( array( $this->record( 5, 9 ), $this->record( 6, 9 ), $this->record( 5, 9 ) ) );
		$this->docs->method( 'findByPersonId' )->willReturn( null );

		$row = $this->firstRow( $this->parents(), array( 'ids' => array( 9 ) ) );

		self::assertSame( '5; 6', $row['ID ученика'] );
		self::assertArrayNotHasKey( 'ИНН', $row );
		self::assertArrayNotHasKey( 'Ученик: ИНН', $row );
	}

	public function test_parent_documents_block_includes_parent_and_children(): void {
		$this->persons->method( 'find' )->willReturnCallback( fn( int $id ) => match ( $id ) {
			9 => $this->person( 9, false, 'Петрова', '1980-01-02' ),
			5 => $this->person( 5, true, 'Петров', '2010-03-04' ),
			6 => $this->person( 6, true, 'Петрова-мл', '2012-05-06' ),
		} );
		$this->records->method( 'findAllByParent' )->willReturn( array( $this->record( 5, 9 ), $this->record( 6, 9 ) ) );
		$this->docs->method( 'findByPersonId' )->willReturnCallback( static fn( int $id ) => match ( $id ) {
			9       => PersonDocumentsDTO::fromArray( array( 'id' => 1, 'person_id' => 9, 'doc_type' => 'pass', 'doc_number_enc' => 'P9', 'inn_enc' => 'I9' ) ),
			5       => PersonDocumentsDTO::fromArray( array( 'id' => 2, 'person_id' => 5, 'doc_type' => 'birth_certificate', 'inn_enc' => 'I5' ) ),
			default => null,
		} );

		$row = $this->firstRow( $this->parents(), array( 'ids' => array( 9 ), 'include_documents' => true ) );

		self::assertSame( 'Паспорт', $row['Документ'] );
		self::assertSame( 'dec:P9', $row['Номер документа'] );
		self::assertSame( 'dec:I9', $row['ИНН'] );
		self::assertSame( '1980-01-02', $row['Дата рожд.'] );
		// Дети — в порядке «ID ученика»; у второго документов нет, но место сохраняется.
		self::assertSame( 'dec:I5; ', $row['Ученик: ИНН'] );
		self::assertSame( 'Свидетельство о рождении; ', $row['Ученик: Документ'] );
		self::assertSame( '2010-03-04; 2012-05-06', $row['Ученик: Дата рожд.'] );
	}
}
