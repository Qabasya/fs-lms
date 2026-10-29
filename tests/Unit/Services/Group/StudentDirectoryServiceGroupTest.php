<?php

declare( strict_types=1 );

namespace Unit\Services\Group;

use Inc\DTO\Enrollment\StudentRecordDTO;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Course\TeacherGroupResolver;
use Inc\Services\Group\ServiceGroupResolver;
use Inc\Services\Group\StudentDirectoryService;
use PHPUnit\Framework\TestCase;

/**
 * Справочник «направление → группа → ученик» (сводка по ученику): служебная группа модуля в него не входит —
 * иначе пикер зарастает сотнями временных учеников.
 */
class StudentDirectoryServiceGroupTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['_fs_test_filter_returns'] );
		parent::tearDown();
	}

	private function record( int $id, int $groupId, int $personId ): StudentRecordDTO {
		return StudentRecordDTO::fromArray( array(
			'id' => $id, 'student_person_id' => $personId, 'parent_person_id' => 0, 'group_id' => $groupId, 'status' => 'active',
			'snapshot_last_name' => 'Фамилия' . $personId, 'snapshot_first_name' => 'Имя',
			'enrolled_at' => '2026-09-01 00:00:00', 'created_at' => '2026-09-01 00:00:00', 'updated_at' => '2026-09-01 00:00:00',
		) );
	}

	private function directory( array $serviceGroupIds ): StudentDirectoryService {
		$GLOBALS['_fs_test_filter_returns']['fs_lms_service_group_ids'] = $serviceGroupIds;

		$teacherGroups = $this->createStub( TeacherGroupResolver::class );
		$teacherGroups->method( 'idsFor' )->willReturn( array( 31, 32 ) );

		$records = $this->createStub( StudentRecordRepository::class );
		$records->method( 'findActiveByGroupId' )->willReturnCallback(
			fn ( int $groupId ): array => 31 === $groupId ? array( $this->record( 1, 31, 101 ), $this->record( 2, 31, 102 ) ) : array( $this->record( 3, 32, 201 ) )
		);

		$groups = $this->createStub( GroupsRepository::class );
		$groups->method( 'findById' )->willReturnCallback(
			static fn ( int $id ): object => (object) array( 'id' => $id, 'name' => 'Группа ' . $id, 'subject_key' => 'inf' )
		);

		return new StudentDirectoryService( $teacherGroups, $records, $groups, $this->createStub( SubjectRepository::class ), new ServiceGroupResolver() );
	}

	public function test_a_service_group_and_its_students_are_not_in_the_directory(): void {
		$result = $this->directory( array( 31 ) )->forTeacher( 5, true );

		self::assertSame( array( 32 ), array_column( $result['groups'], 'id' ) );
		self::assertSame( array( 201 ), array_column( $result['students'], 'person_id' ) );
	}

	public function test_without_service_groups_every_group_is_listed(): void {
		$result = $this->directory( array() )->forTeacher( 5, true );

		self::assertSame( array( 31, 32 ), array_column( $result['groups'], 'id' ) );
		self::assertSame( array( 101, 102, 201 ), array_column( $result['students'], 'person_id' ) );
	}
}
