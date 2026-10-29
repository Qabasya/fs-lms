<?php

declare( strict_types=1 );

namespace Unit\Services\Subject\Bundle;

use Inc\DTO\Enrollment\StudentRecordDTO;
use Inc\Repositories\OptionsRepositories\UserRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\PersonDocumentsRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Group\ServiceGroupResolver;
use Inc\Services\Security\PiiCryptoService;
use Inc\Services\Subject\Bundle\StudentBundleExporter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Перенос предмета между сайтами: служебная группа модуля (её ученики — временные аккаунты с паролями в открытом виде)
 * в пакет не попадает ни группой, ни записями.
 */
class StudentBundleExporterServiceGroupTest extends TestCase {

	private GroupsRepository&MockObject $groups;
	private StudentRecordRepository&MockObject $records;
	private PersonRepository&MockObject $persons;
	private StudentBundleExporter $exporter;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_fs_test_filter_returns']['fs_lms_service_group_ids'] = array( 31 );

		$this->groups  = $this->createMock( GroupsRepository::class );
		$this->records = $this->createMock( StudentRecordRepository::class );
		$this->persons = $this->createMock( PersonRepository::class );

		$this->exporter = new StudentBundleExporter(
			$this->groups,
			$this->records,
			$this->persons,
			$this->createStub( PersonDocumentsRepository::class ),
			$this->createStub( UserRepository::class ),
			$this->createStub( PiiCryptoService::class ),
			new ServiceGroupResolver()
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_fs_test_filter_returns'] );
		parent::tearDown();
	}

	private function group( int $id ): object {
		return (object) array( 'id' => $id, 'name' => 'Группа ' . $id, 'academic_period_id' => '2026_2027', 'course_id' => 0 );
	}

	private function record( int $id, int $groupId, int $personId ): StudentRecordDTO {
		return StudentRecordDTO::fromArray( array(
			'id' => $id, 'student_person_id' => $personId, 'parent_person_id' => 900 + $personId, 'group_id' => $groupId, 'status' => 'active',
			'enrolled_at' => '2026-09-01 00:00:00', 'created_at' => '2026-09-01 00:00:00', 'updated_at' => '2026-09-01 00:00:00',
		) );
	}

	public function test_a_service_group_and_its_records_stay_out_of_the_bundle(): void {
		$this->groups->method( 'findBySubjectKey' )->willReturn( array( $this->group( 31 ), $this->group( 32 ) ) );
		$asked = array();
		$this->records->method( 'findActiveByGroupId' )->willReturnCallback(
			function ( int $groupId ) use ( &$asked ): array {
				$asked[] = $groupId;
				return 31 === $groupId ? array( $this->record( 1, 31, 101 ), $this->record( 2, 31, 102 ) ) : array( $this->record( 3, 32, 201 ) );
			}
		);

		$result = $this->exporter->collect( 'inf', array() );

		self::assertSame( array( 32 ), array_column( $result['data']['groups'], 'source_id' ), 'служебная группа не уезжает' );
		self::assertSame( array( 201 ), array_column( $result['data']['records'], 'student_person_ref' ) );
		self::assertSame( 1, $result['count'] );
		self::assertSame( array( 32 ), $asked, 'записи служебной группы даже не читаются' );
	}

	public function test_without_service_groups_everything_is_exported_as_before(): void {
		unset( $GLOBALS['_fs_test_filter_returns'] );
		$this->exporter = new StudentBundleExporter(
			$this->groups,
			$this->records,
			$this->persons,
			$this->createStub( PersonDocumentsRepository::class ),
			$this->createStub( UserRepository::class ),
			$this->createStub( PiiCryptoService::class ),
			new ServiceGroupResolver()
		);
		$this->groups->method( 'findBySubjectKey' )->willReturn( array( $this->group( 31 ), $this->group( 32 ) ) );
		$this->records->method( 'findActiveByGroupId' )->willReturnCallback(
			fn ( int $groupId ): array => array( $this->record( $groupId, $groupId, 100 + $groupId ) )
		);

		$result = $this->exporter->collect( 'inf', array() );

		self::assertSame( array( 31, 32 ), array_column( $result['data']['groups'], 'source_id' ) );
		self::assertSame( 2, $result['count'] );
	}
}
