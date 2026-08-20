<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\Contracts\ClockInterface;
use Inc\Enums\Profile\NotificationType;
use Inc\DTO\Course\SubstitutionDTO;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\SubstitutionRepository;
use Inc\Services\Course\SubstitutionService;
use Inc\Services\Profile\NotificationService;
use PHPUnit\Framework\TestCase;

class SubstitutionServiceTest extends TestCase {

	private SubstitutionRepository&\PHPUnit\Framework\MockObject\MockObject $repo;
	private GroupsRepository&\PHPUnit\Framework\MockObject\MockObject $groups;
	private NotificationService&\PHPUnit\Framework\MockObject\MockObject $notifications;
	private ClockInterface&\PHPUnit\Framework\MockObject\MockObject $clock;
	private SubstitutionService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->repo          = $this->createMock( SubstitutionRepository::class );
		$this->groups        = $this->createMock( GroupsRepository::class );
		$this->notifications = $this->createMock( NotificationService::class );
		$this->clock         = $this->createMock( ClockInterface::class );
		// Все фикстуры дат в тесте — весна 2026; «сегодня» держим до них, чтобы
		// проверка «период уже прошёл» не срабатывала там, где проверяется другое.
		$this->clock->method( 'now' )->willReturn( '2026-04-01 09:00:00' );
		$this->service       = new SubstitutionService( $this->repo, $this->groups, $this->notifications, $this->clock );
	}

	public function test_assign_creates_with_original_teacher_snapshot(): void {
		$this->groups->method( 'findById' )->with( 7 )->willReturn( (object) array( 'teacher_id' => 42, 'name' => 'ОГЭ-1' ) );
		$this->repo->expects( self::once() )
			->method( 'create' )
			->with( self::callback(
				static fn( $data ) => 7 === $data['group_id']
					&& 42 === $data['original_teacher_id']
					&& 55 === $data['substitute_teacher_id']
					&& '2026-05-01' === $data['valid_from']
					&& '2026-05-31' === $data['valid_to']
			) )
			->willReturn( 3 );

		self::assertSame( 3, $this->service->assign( 7, 55, '2026-05-01', '2026-05-31', 'Отпуск', 9 ) );
	}

	public function test_assign_pushes_notification_to_substitute_teacher(): void {
		$this->groups->method( 'findById' )->willReturn( (object) array( 'teacher_id' => 42, 'name' => 'ОГЭ-1' ) );
		$this->repo->method( 'create' )->willReturn( 3 );
		$this->notifications->method( 'groupStudentUserIds' )->willReturn( array() );

		$calls = array();
		$this->notifications->expects( self::exactly( 2 ) )
			->method( 'push' )
			->willReturnCallback( function ( ...$args ) use ( &$calls ) {
				$calls[] = $args;
			} );

		$this->service->assign( 7, 55, '2026-05-01', '2026-05-31', 'Отпуск', 9 );

		self::assertSame( array( 55 ), $calls[0][0] );
		self::assertSame( NotificationType::SubstituteAssigned, $calls[0][1] );
		self::assertSame( 'sub:3', $calls[0][2] );
		self::assertSame( 'ОГЭ-1', $calls[0][3]['group_name'] );
		self::assertSame( '2026-05-01', $calls[0][3]['valid_from'] );
		self::assertSame( '2026-05-31', $calls[0][3]['valid_to'] );
		self::assertSame( 'Отпуск', $calls[0][3]['reason'] );
	}

	public function test_assign_pushes_notification_to_group_students(): void {
		$this->groups->method( 'findById' )->willReturn( (object) array( 'teacher_id' => 42, 'name' => 'ОГЭ-1' ) );
		$this->repo->method( 'create' )->willReturn( 3 );
		$this->notifications->method( 'groupStudentUserIds' )->with( 7 )->willReturn( array( 101, 102 ) );

		$calls = array();
		$this->notifications->expects( self::exactly( 2 ) )
			->method( 'push' )
			->willReturnCallback( function ( ...$args ) use ( &$calls ) {
				$calls[] = $args;
			} );

		$this->service->assign( 7, 55, '2026-05-01', '2026-05-31', 'Отпуск', 9 );

		self::assertSame( array( 101, 102 ), $calls[1][0] );
		self::assertSame( NotificationType::SubstituteAssignedStudent, $calls[1][1] );
		self::assertSame( 'sub-student:3', $calls[1][2] );
		self::assertSame( 'ОГЭ-1', $calls[1][3]['group_name'] );
		self::assertSame( '2026-05-01', $calls[1][3]['valid_from'] );
		self::assertSame( '2026-05-31', $calls[1][3]['valid_to'] );
	}

	public function test_revoke_retracts_notifications_for_substitute_and_students(): void {
		$this->repo->method( 'find' )->with( 3 )->willReturn( SubstitutionDTO::fromArray( array(
			'id'                    => 3,
			'group_id'              => 7,
			'original_teacher_id'   => 42,
			'substitute_teacher_id' => 55,
			'valid_from'            => '2026-05-01',
			'valid_to'              => '2026-05-31',
			'reason'                => null,
			'approved_by'           => 9,
			'created_at'            => '2026-04-01 00:00:00',
		) ) );
		$this->notifications->method( 'groupStudentUserIds' )->with( 7 )->willReturn( array( 101, 102 ) );

		$this->notifications->expects( self::exactly( 2 ) )
			->method( 'retract' )
			->willReturnMap( array(
				array( array( 55 ), 'sub:3', null ),
				array( array( 101, 102 ), 'sub-student:3', null ),
			) );
		$this->repo->expects( self::once() )->method( 'delete' )->with( 3 );

		$this->service->revoke( 3 );
	}

	public function test_assign_throws_when_group_missing(): void {
		$this->groups->method( 'findById' )->willReturn( null );
		$this->repo->expects( self::never() )->method( 'create' );

		$this->expectException( \InvalidArgumentException::class );
		$this->service->assign( 7, 55, '2026-05-01', '2026-05-31', null, 9 );
	}

	public function test_assign_throws_when_from_after_to(): void {
		$this->groups->method( 'findById' )->willReturn( (object) array( 'teacher_id' => 42 ) );
		$this->repo->expects( self::never() )->method( 'create' );

		$this->expectException( \InvalidArgumentException::class );
		$this->service->assign( 7, 55, '2026-05-31', '2026-05-01', null, 9 );
	}

	public function test_assign_throws_on_invalid_date(): void {
		$this->groups->method( 'findById' )->willReturn( (object) array( 'teacher_id' => 42 ) );
		$this->repo->expects( self::never() )->method( 'create' );

		$this->expectException( \InvalidArgumentException::class );
		$this->service->assign( 7, 55, '2026-13-40', '2026-05-31', null, 9 );
	}

	public function test_assign_rejects_substitute_equal_to_group_teacher(): void {
		$this->groups->method( 'findById' )->willReturn( (object) array( 'teacher_id' => 42 ) );
		$this->repo->expects( self::never() )->method( 'create' );

		$this->expectExceptionMessage( 'Замещающий совпадает с преподавателем группы.' );
		$this->service->assign( 7, 42, '2026-05-01', '2026-05-31', null, 9 );
	}

	public function test_assign_rejects_period_entirely_in_the_past(): void {
		$this->groups->method( 'findById' )->willReturn( (object) array( 'teacher_id' => 42 ) );
		$this->repo->expects( self::never() )->method( 'create' );

		// «Сегодня» у часов — 2026-04-01, период закончился раньше.
		$this->expectExceptionMessage( 'Период замены уже прошёл' );
		$this->service->assign( 7, 55, '2026-03-01', '2026-03-10', null, 9 );
	}

	public function test_assign_rejects_overlapping_period(): void {
		$this->groups->method( 'findById' )->willReturn( (object) array( 'teacher_id' => 42 ) );
		$this->repo->method( 'findOverlapping' )->with( 7, '2026-05-10', '2026-05-20' )->willReturn(
			SubstitutionDTO::fromArray( array(
				'id'                    => 1,
				'group_id'              => 7,
				'original_teacher_id'   => 42,
				'substitute_teacher_id' => 55,
				'valid_from'            => '2026-05-01',
				'valid_to'              => '2026-05-31',
				'reason'                => null,
				'approved_by'           => 9,
				'created_at'            => '2026-04-01 00:00:00',
			) )
		);
		$this->repo->expects( self::never() )->method( 'create' );

		$this->expectExceptionMessage( '01.05.2026 — 31.05.2026' );
		$this->service->assign( 7, 56, '2026-05-10', '2026-05-20', null, 9 );
	}

	public function test_assign_allows_period_started_in_the_past_but_still_running(): void {
		$this->groups->method( 'findById' )->willReturn( (object) array( 'teacher_id' => 42, 'name' => 'ОГЭ-1' ) );
		$this->repo->expects( self::once() )->method( 'create' )->willReturn( 5 );

		// Начало раньше «сегодня» (2026-04-01), но период ещё идёт — это норма
		// (замену оформляют задним числом в день болезни).
		self::assertSame( 5, $this->service->assign( 7, 55, '2026-03-30', '2026-04-10', null, 9 ) );
	}
}
