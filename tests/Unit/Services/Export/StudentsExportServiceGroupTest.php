<?php

declare( strict_types=1 );

namespace Unit\Services\Export;

use Inc\DTO\Enrollment\StudentRecordDTO;
use Inc\DTO\Person\PersonDTO;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\OptionsRepositories\UserRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\PersonDocumentsRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Export\StudentsExportProvider;
use Inc\Services\Group\ServiceGroupResolver;
use Inc\Services\Security\PiiCryptoService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** Выгрузка учеников: тот, у кого все записи в служебных группах модулей, в неё не входит. */
class StudentsExportServiceGroupTest extends TestCase {

	private PersonRepository&MockObject $persons;
	private StudentRecordRepository&MockObject $records;
	private StudentsExportProvider $provider;

	protected function setUp(): void {
		parent::setUp();
		unset( $GLOBALS['_fs_test_filter_returns'] );

		$this->persons = $this->createMock( PersonRepository::class );
		$this->records = $this->createMock( StudentRecordRepository::class );
		$docs          = $this->createMock( PersonDocumentsRepository::class );
		$docs->method( 'findByPersonId' )->willReturn( null );

		$this->provider = new StudentsExportProvider(
			$this->persons,
			$this->records,
			$docs,
			$this->createMock( GroupsRepository::class ),
			$this->createMock( SubjectRepository::class ),
			$this->createMock( UserRepository::class ),
			$this->createMock( PiiCryptoService::class ),
			new ServiceGroupResolver()
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_fs_test_filter_returns'] );
		parent::tearDown();
	}

	private function person( int $id ): PersonDTO {
		return PersonDTO::fromArray( array(
			'id' => $id, 'last_name' => 'Ученик' . $id, 'first_name' => 'И', 'is_student' => true,
			'created_at' => '2024-01-01', 'updated_at' => '2024-01-01',
		) );
	}

	private function record( int $student, int $group ): StudentRecordDTO {
		return StudentRecordDTO::fromArray( array(
			'id' => $student * 100 + $group, 'student_person_id' => $student, 'parent_person_id' => 0, 'group_id' => $group,
		) );
	}

	/** @param array<int, list<int>> $groupsByStudent person_id → ID групп его активных записей */
	private function exportedIds( array $groupsByStudent ): array {
		$this->persons->method( 'findByIsStudent' )->willReturn( array_map( fn( int $id ) => $this->person( $id ), array_keys( $groupsByStudent ) ) );
		$this->records->method( 'findActiveByStudent' )->willReturnCallback(
			fn( int $personId ): array => array_map( fn( int $g ) => $this->record( $personId, $g ), $groupsByStudent[ $personId ] ?? array() )
		);

		return array_column( iterator_to_array( $this->provider->rows( array() ), false ), 'person_id' );
	}

	public function test_without_service_groups_everyone_is_exported(): void {
		self::assertSame( array( 1, 2, 3 ), $this->exportedIds( array( 1 => array( 10 ), 2 => array( 20 ), 3 => array() ) ) );
	}

	public function test_student_whose_records_are_all_in_service_groups_is_skipped(): void {
		$GLOBALS['_fs_test_filter_returns']['fs_lms_service_group_ids'] = array( 20, 21 );

		self::assertSame( array( 1 ), $this->exportedIds( array( 1 => array( 10 ), 2 => array( 20 ), 3 => array( 20, 21 ) ) ) );
	}

	public function test_student_with_a_real_group_stays_even_if_one_record_is_service(): void {
		$GLOBALS['_fs_test_filter_returns']['fs_lms_service_group_ids'] = array( 20 );

		self::assertSame( array( 1 ), $this->exportedIds( array( 1 => array( 10, 20 ) ) ) );
	}

	public function test_student_without_any_record_is_not_treated_as_service(): void {
		$GLOBALS['_fs_test_filter_returns']['fs_lms_service_group_ids'] = array( 20 );

		self::assertSame( array( 3 ), $this->exportedIds( array( 3 => array() ) ) );
	}
}
