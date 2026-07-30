<?php

declare( strict_types=1 );

namespace Unit\Services\Subject\Bundle;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Import\AccountCredentialsDTO;
use Inc\DTO\Settings\AcademicPeriodDTO;
use Inc\DTO\Subject\ImportedEntitiesDTO;
use Inc\Managers\Wp\PostManager;
use Inc\Repositories\OptionsRepositories\AcademicPeriodRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Enrollment\AccountProvisioningService;
use Inc\Services\Import\StudentRecordWriter;
use Inc\Services\Subject\Bundle\ExportIdMapper;
use Inc\Services\Subject\Bundle\StudentBundleImporter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Перенос учеников: группы, лица, учётки и зачисление — без прогресса.
 */
class StudentBundleImporterTest extends TestCase {

	public function test_creates_group_linked_to_imported_course(): void {
		$captured = null;

		$groups = $this->createMock( GroupsRepository::class );
		$groups->method( 'findByNameSubjectPeriod' )->willReturn( null );
		$groups->method( 'create' )->willReturnCallback( function ( array $data ) use ( &$captured ): int {
			$captured = $data;
			return 300;
		} );

		// Курс из этого же пакета получил на целевом сайте новый ID.
		$mapper = new ExportIdMapper();
		$mapper->bind( 'courses:90', 555 );

		$this->makeImporter( groups: $groups )->restore(
			$this->students(),
			'math',
			$mapper,
			new ImportedEntitiesDTO()
		);

		self::assertSame( 'Группа А', $captured['name'] );
		self::assertSame( 'math', $captured['subject_key'] );
		self::assertSame( 555, $captured['course_id'], 'группа должна ссылаться на импортированный курс' );
	}

	public function test_group_without_course_in_package_is_created_without_program(): void {
		$captured = null;

		$groups = $this->createMock( GroupsRepository::class );
		$groups->method( 'findByNameSubjectPeriod' )->willReturn( null );
		$groups->method( 'create' )->willReturnCallback( function ( array $data ) use ( &$captured ): int {
			$captured = $data;
			return 300;
		} );

		// Карта пуста — курс не переносился.
		$this->makeImporter( groups: $groups )->restore(
			$this->students(),
			'math',
			new ExportIdMapper(),
			new ImportedEntitiesDTO()
		);

		self::assertArrayNotHasKey( 'course_id', $captured, 'чужой ID курса переносить нельзя' );
	}

	public function test_enrolls_students_and_records_created_entities(): void {
		$created = new ImportedEntitiesDTO();

		$result = $this->makeImporter()->restore( $this->students(), 'math', new ExportIdMapper(), $created );

		self::assertSame( 1, $result['count'] );
		self::assertSame( 1, $created->counts()['groups'] );
		self::assertSame( 2, $created->counts()['persons'], 'ученик и представитель' );
		self::assertSame( 2, $created->counts()['accounts'] );
	}

	public function test_keeps_passwords_from_the_package(): void {
		$studentPassword = null;
		$parentPassword  = null;

		$provisioning = $this->createMock( AccountProvisioningService::class );
		$provisioning->method( 'provisionStudent' )->willReturnCallback(
			static function ( int $personId, $data, string $login, string $password ) use ( &$studentPassword ): AccountCredentialsDTO {
				$studentPassword = $password;
				return new AccountCredentialsDTO( 801, $login, $password, true );
			}
		);
		$provisioning->method( 'provisionParent' )->willReturnCallback(
			static function ( int $personId, $data, ?string $password = null ) use ( &$parentPassword ): AccountCredentialsDTO {
				$parentPassword = $password;
				return new AccountCredentialsDTO( 802, 'parent@example.com', (string) $password, true );
			}
		);

		$result = $this->makeImporter( provisioning: $provisioning )->restore(
			$this->students(),
			'math',
			new ExportIdMapper(),
			new ImportedEntitiesDTO()
		);

		// Семья должна войти на новом сайте по прежним данным.
		self::assertSame( 'stud-secret', $studentPassword );
		self::assertSame( 'parent-secret', $parentPassword );
		self::assertCount( 2, $result['credentials'] );
	}

	public function test_generates_password_only_when_package_has_none(): void {
		$captured = array();

		$provisioning = $this->createMock( AccountProvisioningService::class );
		$provisioning->method( 'provisionStudent' )->willReturnCallback(
			static function ( int $personId, $data, string $login, string $password ) use ( &$captured ): AccountCredentialsDTO {
				$captured['student'] = $password;
				return new AccountCredentialsDTO( 801, $login, $password, true );
			}
		);
		$provisioning->method( 'provisionParent' )->willReturnCallback(
			static function ( int $personId, $data, ?string $password = null ) use ( &$captured ): AccountCredentialsDTO {
				$captured['parent'] = $password;
				return new AccountCredentialsDTO( 802, 'parent@example.com', 'generated', true );
			}
		);

		$students = $this->students();
		$students['persons'][0]['password'] = '';
		$students['persons'][1]['password'] = '';

		$result = $this->makeImporter( provisioning: $provisioning )->restore(
			$students,
			'math',
			new ExportIdMapper(),
			new ImportedEntitiesDTO()
		);

		self::assertNotEmpty( $captured['student'], 'ученику выдаётся новый пароль' );
		// null → провизия сгенерирует пароль сама.
		self::assertNull( $captured['parent'] );
		self::assertStringContainsString( 'нет пароля', implode( ' ', $result['warnings'] ) );
	}

	public function test_existing_person_is_reused_and_not_scheduled_for_rollback(): void {
		$writer = $this->createMock( StudentRecordWriter::class );
		$writer->method( 'resolvePersonId' )->willReturn( 42 );
		$writer->expects( self::never() )->method( 'createPerson' );

		$created = new ImportedEntitiesDTO();
		$this->makeImporter( writer: $writer )->restore(
			$this->students(),
			'math',
			new ExportIdMapper(),
			$created
		);

		self::assertSame( 0, $created->counts()['persons'] );
		self::assertSame( 0, $created->counts()['accounts'], 'учётку существующему лицу не пересоздаём' );
	}

	public function test_duplicate_enrollment_is_skipped(): void {
		$records = $this->createMock( StudentRecordRepository::class );
		$records->method( 'existsByContract' )->willReturn( true );
		$records->expects( self::never() )->method( 'create' );

		$result = $this->makeImporter( records: $records )->restore(
			$this->students(),
			'math',
			new ExportIdMapper(),
			new ImportedEntitiesDTO()
		);

		self::assertSame( 0, $result['count'] );
	}

	public function test_warns_that_period_is_replaced_by_current_one(): void {
		$result = $this->makeImporter()->restore(
			$this->students(),
			'math',
			new ExportIdMapper(),
			new ImportedEntitiesDTO()
		);

		self::assertStringContainsString( 'учебный период', implode( ' ', $result['warnings'] ) );
	}

	public function test_fails_when_target_site_has_no_current_period(): void {
		$periods = $this->createMock( AcademicPeriodRepository::class );
		$periods->method( 'getCurrentPeriod' )->willReturn( null );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/учебный период/u' );

		$this->makeImporter( periods: $periods )->restore(
			$this->students(),
			'math',
			new ExportIdMapper(),
			new ImportedEntitiesDTO()
		);
	}

	/**
	 * Собирает импортёр с моками по умолчанию.
	 */
	private function makeImporter(
		?GroupsRepository $groups = null,
		?StudentRecordRepository $records = null,
		?StudentRecordWriter $writer = null,
		?AcademicPeriodRepository $periods = null,
		?AccountProvisioningService $provisioning = null
	): StudentBundleImporter {
		if ( null === $groups ) {
			$groups = $this->createMock( GroupsRepository::class );
			$groups->method( 'findByNameSubjectPeriod' )->willReturn( null );
			$groups->method( 'create' )->willReturn( 300 );
		}

		if ( null === $records ) {
			$records = $this->createMock( StudentRecordRepository::class );
			$records->method( 'existsByContract' )->willReturn( false );
			$records->method( 'create' )->willReturn( 900 );
		}

		if ( null === $writer ) {
			$nextPersonId = 400;
			$writer       = $this->createMock( StudentRecordWriter::class );
			$writer->method( 'resolvePersonId' )->willReturn( null );
			$writer->method( 'createPerson' )->willReturnCallback( static function () use ( &$nextPersonId ): int {
				return ++$nextPersonId;
			} );
		}

		if ( null === $periods ) {
			$periods = $this->createMock( AcademicPeriodRepository::class );
			$periods->method( 'getCurrentPeriod' )->willReturn(
				new AcademicPeriodDTO( '2026-2027', '2026/2027', '2026-09-01', '2027-05-31', true )
			);
		}

		if ( null === $provisioning ) {
			$nextUserId   = 800;
			$provisioning = $this->createMock( AccountProvisioningService::class );
			$provisioning->method( 'provisionStudent' )->willReturnCallback(
				static function () use ( &$nextUserId ): AccountCredentialsDTO {
					return new AccountCredentialsDTO( ++$nextUserId, 'ivanov', 'pass-' . $nextUserId, true );
				}
			);
			$provisioning->method( 'provisionParent' )->willReturnCallback(
				static function () use ( &$nextUserId ): AccountCredentialsDTO {
					return new AccountCredentialsDTO( ++$nextUserId, 'parent@example.com', 'pass-' . $nextUserId, true );
				}
			);
		}

		$posts = $this->createMock( PostManager::class );
		$posts->method( 'get' )->willReturn( new \WP_Post() );

		$clock = $this->createMock( ClockInterface::class );
		$clock->method( 'now' )->willReturn( '2026-07-30 12:00:00' );

		return new StudentBundleImporter( $groups, $records, $writer, $provisioning, $periods, $posts, $clock );
	}

	/**
	 * Раздел `students` пакета: одна группа, ученик с представителем, одно зачисление.
	 *
	 * @return array<string, mixed>
	 */
	private function students(): array {
		return array(
			'groups'  => array(
				array(
					'source_id'  => 7,
					'name'       => 'Группа А',
					'period_id'  => '2025-2026',
					'course_ref' => 'courses:90',
				),
			),
			'persons' => array(
				array(
					'source_id'  => 1,
					'last_name'  => 'Иванов',
					'first_name' => 'Иван',
					'is_student' => true,
					'login'      => 'ivanov',
					'password'   => 'stud-secret',
					'email'      => 'ivan@example.com',
					'doc_number' => 'AB123',
				),
				array(
					'source_id'  => 2,
					'last_name'  => 'Иванова',
					'first_name' => 'Мария',
					'is_student' => false,
					'password'   => 'parent-secret',
					'email'      => 'parent@example.com',
					'doc_number' => 'CD456',
				),
			),
			'records' => array(
				array(
					'group_source_id'     => 7,
					'student_person_ref'  => 1,
					'parent_person_ref'   => 2,
					'contract_no'         => 'Д-1',
					'enrolled_at'         => '2025-09-01 00:00:00',
					'snapshot_last_name'  => 'Иванов',
					'snapshot_first_name' => 'Иван',
				),
			),
		);
	}
}
