<?php

declare( strict_types=1 );

namespace Unit\Services\Profile;

use Inc\Enums\Access\UserRole;
use Inc\Managers\Person\UserManager;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\Profile\HomeLayoutRepository;
use Inc\Services\Profile\HomeLayoutService;
use Inc\Services\Profile\HomeLayoutStatisticsService;
use PHPUnit\Framework\TestCase;

class HomeLayoutStatisticsServiceTest extends TestCase {

	public function test_counts_saved_positions_and_unique_explicit_tiles_for_each_view_role(): void {
		$report = $this->report( array(
			$this->row( UserRole::FSStudent, array( 'schedule', 'tiles', 'deadlines' ), array( 'attendance', 'attendance', 'streak' ) ),
			$this->row( UserRole::FSParent, array( 'tiles', 'deadlines', 'schedule' ), array( 'attendance' ) ),
			$this->row( UserRole::FSTeacher, array( 'attention', 'schedule', 'tiles' ), array( 'lessons_today', 'to_review' ) ),
			$this->row( UserRole::FSOffice, array( 'tiles', 'schedule', 'attention' ), array( 'to_review' ) ),
		) );
		self::assertSame( 4, $report['users'] );
		self::assertSame( array( 2, 1, 1 ), $report['sections']['tiles']['positions'] );
		self::assertSame( array( 1, 2, 1 ), $report['sections']['schedule']['positions'] );
		self::assertSame( array( 1, 0, 1 ), $report['sections']['attention']['positions'] );
		self::assertSame( 2, $report['tiles']['attendance']['users'] );
		self::assertSame( 2, $report['tiles']['to_review']['users'] );
	}

	public function test_automatic_tiles_are_not_invented_and_malformed_or_unsupported_rows_are_skipped(): void {
		$report = $this->report( array(
			$this->row( UserRole::FSStudent, array( 'tiles', 'schedule', 'deadlines' ), null ),
			$this->row( UserRole::FSStudent, array( 'tiles', 'schedule', 'deadlines' ), array() ),
			$this->row( UserRole::FSStudent, array( 'tiles', 'tiles', 'deadlines' ), array( 'attendance' ) ),
			$this->row( UserRole::FSMethodist, array( 'tiles', 'schedule', 'deadlines' ), array( 'attendance' ) ),
			array( 'roles' => array( 'subscriber' ), 'layout' => array( 'sections' => array( 'tiles', 'schedule', 'deadlines' ), 'tiles' => array( 'attendance' ) ) ),
			array( 'roles' => array( UserRole::FSStudent->value ), 'layout' => 'corrupt' ),
		) );
		self::assertSame( 2, $report['users'] );
		self::assertSame( 1, $report['automatic'] );
		self::assertSame( 0, $report['tiles']['attendance']['users'] );
	}

	public function test_office_defaults_for_wp_admin_and_role_specific_tile_validation(): void {
		$admin = $this->row( UserRole::FSOffice, array( 'attention', 'tiles', 'schedule' ), array( 'to_review', 'attendance' ) );
		$admin['roles'] = array( 'administrator' );
		$report = $this->report( array( $admin, $this->row( UserRole::FSParent, array( 'tiles', 'schedule', 'deadlines' ), array( 'lessons_today', 'attendance' ) ) ) );
		self::assertSame( 2, $report['users'] );
		self::assertSame( 1, $report['tiles']['attendance']['users'] );
		self::assertSame( 1, $report['tiles']['to_review']['users'] );
		self::assertSame( 0, $report['tiles']['lessons_today']['users'] );
	}

	private function row( UserRole $role, array $sections, ?array $tiles ): array {
		return array( 'roles' => array( $role->value ), 'layout' => array( 'sections' => $sections, 'tiles' => $tiles ) );
	}

	private function report( array $rows ): array {
		$repository = $this->createMock( HomeLayoutRepository::class );
		$repository->expects( self::once() )->method( 'savedLayouts' )->willReturn( $rows );
		$subjects = $this->createMock( SubjectRepository::class );
		$subjects->expects( self::once() )->method( 'readAll' )->willReturn( array() );
		$users = $this->createStub( UserManager::class );
		return ( new HomeLayoutStatisticsService( $repository, new HomeLayoutService( $users ), $subjects ) )->build();
	}
}
