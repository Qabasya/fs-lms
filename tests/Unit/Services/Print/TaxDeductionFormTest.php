<?php

declare( strict_types=1 );

namespace Unit\Services\Print;

use Inc\DTO\Enrollment\StudentRecordDTO;
use Inc\DTO\Person\PersonDocumentsDTO;
use Inc\DTO\Person\PersonDTO;
use Inc\Repositories\WPDBRepositories\PersonDocumentsRepository;
use Inc\Services\Print\PrintDataCollector;
use Inc\Services\Print\TaxDeductionForm;
use PHPUnit\Framework\TestCase;

/**
 * Раскладка данных справки на вычет по полям формы КНД 1151158.
 */
class TaxDeductionFormTest extends TestCase {

	private function build( array $collected, array $input, array $docTypes = array() ): array {
		$collector = $this->createMock( PrintDataCollector::class );
		$collector->method( 'collect' )->willReturn( $collected );

		$documents = $this->createMock( PersonDocumentsRepository::class );
		$documents->method( 'findByPersonId' )->willReturnCallback(
			fn( int $id ): ?PersonDocumentsDTO => isset( $docTypes[ $id ] )
				? PersonDocumentsDTO::fromArray( array( 'id' => 1, 'person_id' => $id, 'doc_type' => $docTypes[ $id ] ) )
				: null
		);

		return ( new TaxDeductionForm( $collector, $documents ) )->build(
			$this->person( 11 ),
			$this->person( 12 ),
			$this->createMock( StudentRecordDTO::class ),
			$input
		);
	}

	private function person( int $id ): PersonDTO {
		return PersonDTO::fromArray( array(
			'id' => $id, 'last_name' => 'X', 'first_name' => 'Y', 'is_student' => 1,
			'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
		) );
	}

	public function test_maps_parent_student_and_input_to_form_fields(): void {
		$form = $this->build(
			array(
				'today'                  => '26.09.2026',
				'parent_last_name'       => 'Новикова',
				'parent_doc_number'      => '4 504 222 226',
				'parent_birth_date'      => '27.01.1985',
				'student_doc_number'     => 'iv-жд 123456',
			),
			array( 'number' => '17', 'year' => '2025', 'sum' => '120 000,5' ),
			array( 12 => 'pass', 11 => 'birth_certificate' )
		);
		$v = $form['values'];

		self::assertSame( '17', $v['Text4'] );
		self::assertSame( '2025', $v['Text3'] );
		self::assertSame( '0', $v['Text14.1'] );             // очная форма — пока всегда 0
		self::assertSame( array( '120000', '50' ), array( $v['Text15.0'], $v['Text16.0'] ) );
		self::assertSame( 'Новикова', $v['Text7.0'] );
		self::assertSame( '45 04 222226', $v['Text13'] );    // паспорт РФ: «ХХ ХХ ХХХХХХ»
		self::assertSame( '21', $v['Text12'] );
		self::assertSame( array( '27', '01', '1985' ), array( $v['Text9.0'], $v['Text10.0'], $v['Text11.0'] ) );
		self::assertSame( '03', $v['Text25'] );              // свидетельство о рождении
		self::assertSame( 'IV-ЖД 123456', $v['Text260'] );
		self::assertSame( '26.09.2026', $v['Text30'] );
	}

	public function test_reports_fields_without_data(): void {
		$form = $this->build( array( 'today' => '26.09.2026' ), array( 'number' => '1', 'year' => '2025', 'sum' => '100' ) );

		self::assertContains( 'Родитель: ИНН', $form['empty'] );
		self::assertContains( 'Ученик: дата рождения', $form['empty'] );
	}
}
