<?php

declare( strict_types=1 );

namespace Unit\Services\Enrollment;

use Inc\Contracts\ClockInterface;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Enrollment\StudentRecordDTO;
use Inc\DTO\Person\PersonDTO;
use Inc\Enums\Enrollment\EnrollmentStatus;
use Inc\Enums\Log\LogEvent;
use Inc\Managers\Person\UserManager;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Enrollment\ExpulsionService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Отчисление и общие (дополнительные) группы: запись общей группы — не зачисление.
 * Её не считают «оставшейся» записью, она не выбирается для отчисления по умолчанию,
 * закрывается вместе с последней основной записью и не трогает аккаунт при самостоятельном выходе.
 */
class ExpulsionServiceTest extends TestCase {

	private const MAIN_GROUP   = 1;
	private const SHARED_GROUP = 2;

	private PersonRepository&MockObject $persons;
	private StudentRecordRepository&MockObject $records;
	private LogEventDispatcherInterface&MockObject $dispatcher;
	private GroupsRepository&MockObject $groups;
	private ExpulsionService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->persons    = $this->createMock( PersonRepository::class );
		$this->records    = $this->createMock( StudentRecordRepository::class );
		$this->dispatcher = $this->createMock( LogEventDispatcherInterface::class );
		$this->groups     = $this->createMock( GroupsRepository::class );

		$clock = $this->createMock( ClockInterface::class );
		$clock->method( 'now' )->willReturn( '2026-10-25 12:00:00' );

		$this->persons->method( 'findByWpUserId' )->willReturn( $this->person( 5 ) );
		$this->persons->method( 'find' )->willReturn( $this->person( 7 ) );
		$this->groups->method( 'findById' )->willReturnCallback(
			static fn ( int $id ): ?object => (object) array( 'id' => $id, 'is_shared' => self::SHARED_GROUP === $id ? 1 : 0 )
		);

		$this->service = new ExpulsionService(
			$this->persons,
			$this->records,
			$this->dispatcher,
			$this->createMock( UserManager::class ),
			$clock,
			$this->groups,
		);

		$GLOBALS['_fs_test_actions'] = array();
	}

	public function test_default_record_is_main_not_shared(): void {
		$main = $this->record( 10, self::MAIN_GROUP );

		// Без указанной записи отчисляется основная: общие записи в выборку не попадают (includeShared = false).
		$this->records->expects( self::atLeastOnce() )->method( 'findActiveByStudent' )->willReturnCallback(
			fn ( int $id, bool $includeShared = true ): array => $includeShared ? array( $this->record( 11, self::SHARED_GROUP ), $main ) : array( $main )
		);
		$this->records->expects( self::once() )->method( 'setExpelled' )->with( 10, self::anything(), self::anything(), 'reason' );

		$result = $this->service->expel( 77, 'reason' );

		self::assertSame( 10, $result['archive_id'] );
	}

	public function test_expelling_last_main_record_closes_shared_records_and_soft_deletes_person(): void {
		$main   = $this->record( 10, self::MAIN_GROUP );
		$shared = $this->record( 11, self::SHARED_GROUP );
		$this->records->method( 'find' )->willReturn( $main );

		// До отчисления активны основная и общая; после — основных нет, общая ещё не закрыта, потом закрыта.
		$closed = false;
		$this->records->method( 'findActiveByStudent' )->willReturnCallback(
			function ( int $id, bool $includeShared = true ) use ( &$closed, $shared ): array {
				if ( $closed ) {
					return array();
				}

				return $includeShared ? array( $shared ) : array();
			}
		);
		$this->records->method( 'setFinished' )->willReturnCallback( function () use ( &$closed ): bool {
			$closed = true;

			return true;
		} );

		$this->records->expects( self::once() )->method( 'setExpelled' )->with( 10, self::anything(), self::anything(), 'reason' );
		$this->records->expects( self::once() )->method( 'setFinished' )->with( 11 );

		// Основного зачисления не осталось: мягко удаляются ученик (5) и родитель (7), у которого других активных детей нет.
		$deleted = array();
		$this->persons->method( 'softDelete' )->willReturnCallback( function ( int $id ) use ( &$deleted ): bool {
			$deleted[] = $id;

			return true;
		} );

		$result = $this->service->expel( 77, 'reason', 10 );

		self::assertSame( array(), $result['remaining_active_records'] );
		self::assertSame( array( 5, 7 ), $deleted );
	}

	public function test_expelling_one_of_two_main_records_keeps_shared_and_account(): void {
		$main1  = $this->record( 10, self::MAIN_GROUP );
		$main2  = $this->record( 12, 3 );
		$shared = $this->record( 11, self::SHARED_GROUP );
		$this->records->method( 'find' )->willReturn( $main1 );
		$this->records->method( 'findActiveByStudent' )->willReturnCallback(
			static fn ( int $id, bool $includeShared = true ): array => $includeShared ? array( $shared, $main2 ) : array( $main2 )
		);
		$this->records->method( 'findActiveByParent' )->willReturn( array( $main2, $shared ) );

		$this->records->expects( self::once() )->method( 'setExpelled' );
		$this->records->expects( self::never() )->method( 'setFinished' );
		$this->persons->expects( self::never() )->method( 'softDelete' );

		$result = $this->service->expel( 77, 'reason', 10 );

		self::assertCount( 2, $result['remaining_active_records'] );
	}

	public function test_leaving_shared_group_only_finishes_that_record(): void {
		$shared = $this->record( 11, self::SHARED_GROUP );
		$this->records->method( 'find' )->willReturn( $shared );
		$this->records->method( 'findActiveByStudent' )->willReturn( array( $this->record( 10, self::MAIN_GROUP ) ) );

		$this->records->expects( self::once() )->method( 'setFinished' )->with( 11 );
		$this->records->expects( self::never() )->method( 'setExpelled' );
		$this->persons->expects( self::never() )->method( 'softDelete' );
		$this->dispatcher->expects( self::once() )->method( 'dispatch' )->with( LogEvent::StudentExpelled, self::anything() );

		$result = $this->service->expel( 77, 'reason', 11 );

		self::assertSame( 11, $result['archive_id'] );
		// Хук отчисления гасит аккаунт ученика (AdSync) — на выход из общей группы он не нужен.
		self::assertNotContains( 'fs_lms_student_expelled', array_column( $GLOBALS['_fs_test_actions'], 'hook' ) );
	}

	public function test_regular_expulsion_still_fires_expelled_hook(): void {
		$main = $this->record( 10, self::MAIN_GROUP );
		$this->records->method( 'find' )->willReturn( $main );
		$this->records->method( 'findActiveByStudent' )->willReturn( array() );

		$this->service->expel( 77, 'reason', 10 );

		self::assertContains( 'fs_lms_student_expelled', array_column( $GLOBALS['_fs_test_actions'], 'hook' ) );
	}

	// --- helpers ---

	private function person( int $id ): PersonDTO {
		return new PersonDTO(
			id: $id, wpUserId: 77, lastName: 'Тестов', firstName: 'Тест', middleName: null,
			birthDate: null, isStudent: true, school: null, grade: null, expelledAt: null,
			createdAt: '2024-01-01 00:00:00', updatedAt: '2024-01-01 00:00:00',
		);
	}

	private function record( int $id, int $groupId ): StudentRecordDTO {
		return StudentRecordDTO::fromArray( array(
			'id'                  => $id,
			'student_person_id'   => 5,
			'parent_person_id'    => 7,
			'group_id'            => $groupId,
			'snapshot_last_name'  => 'Тестов',
			'snapshot_first_name' => 'Тест',
			'status'              => EnrollmentStatus::Active->value,
			'enrolled_at'         => '2024-01-01 00:00:00',
			'created_at'          => '2024-01-01 00:00:00',
			'updated_at'          => '2024-01-01 00:00:00',
		) );
	}
}
