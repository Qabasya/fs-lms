<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Subject;

use Inc\Callbacks\Subject\SubjectCrudCallbacks;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Subject\SubjectDTO;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Services\Deletion\DeletionEventDispatcher;
use Inc\Services\Subject\SubjectArchiveGuard;
use PHPUnit\Framework\TestCase;

class SubjectCrudCallbacksTest extends TestCase {

	private SubjectRepository&\PHPUnit\Framework\MockObject\MockObject       $subjects;
	private DeletionEventDispatcher&\PHPUnit\Framework\MockObject\MockObject $dispatcher;
	private GroupsRepository&\PHPUnit\Framework\MockObject\MockObject        $groups;
	private SubjectArchiveGuard&\PHPUnit\Framework\MockObject\MockObject     $archiveGuard;
	private SubjectCrudCallbacks                                             $cb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$this->subjects     = $this->createMock( SubjectRepository::class );
		$this->dispatcher   = $this->createMock( DeletionEventDispatcher::class );
		$this->groups       = $this->createMock( GroupsRepository::class );
		$this->archiveGuard = $this->createMock( SubjectArchiveGuard::class );
		$this->archiveGuard->method( 'activeGroups' )->willReturn( array() );
		$logEvents          = $this->createMock( LogEventDispatcherInterface::class );
		$this->cb           = new SubjectCrudCallbacks( $this->subjects, $this->dispatcher, $logEvents, $this->groups, $this->archiveGuard );
	}

	public function test_delete_blocked_when_subject_has_groups(): void {
		$this->subjects->method( 'getByKey' )->willReturn( new SubjectDTO( 'math', 'Математика' ) );
		$this->groups->method( 'findBySubjectKey' )->willReturn( array( (object) array( 'id' => 1 ), (object) array( 'id' => 2 ) ) );
		$this->dispatcher->expects( $this->never() )->method( 'dispatch' );
		$_POST = array( 'key' => 'math' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxDeleteSubject() );

		self::assertFalse( $r->success );
		self::assertStringContainsString( 'группы', $r->payload );
	}

	public function test_delete_proceeds_when_no_groups(): void {
		$this->subjects->method( 'getByKey' )->willReturn( new SubjectDTO( 'math', 'Математика' ) );
		$this->groups->method( 'findBySubjectKey' )->willReturn( array() );
		$this->dispatcher->expects( $this->once() )->method( 'dispatch' );
		$_POST = array( 'key' => 'math' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxDeleteSubject() );

		self::assertTrue( $r->success );
	}

	public function test_force_delete_proceeds_with_groups(): void {
		$this->subjects->method( 'getByKey' )->willReturn( new SubjectDTO( 'math', 'Математика' ) );
		$this->groups->method( 'findBySubjectKey' )->willReturn( array( (object) array( 'id' => 1 ), (object) array( 'id' => 2 ) ) );
		$this->dispatcher->expects( $this->once() )->method( 'dispatch' );
		$_POST = array( 'key' => 'math', 'force' => 1 );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxDeleteSubject() );

		self::assertTrue( $r->success );
	}

	public function test_toggle_archive_flips_and_persists(): void {
		$this->subjects->method( 'getByKey' )->willReturn( new SubjectDTO( 'math', 'Математика', false ) );
		$this->subjects->expects( $this->once() )->method( 'setArchived' )->with( 'math', true )->willReturn( true );
		$_POST = array( 'key' => 'math' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxToggleSubjectArchive() );

		self::assertTrue( $r->success );
		self::assertTrue( $r->payload['archived'] );
	}

	public function test_archive_blocked_when_active_groups_in_current_period(): void {
		$subjects = $this->createMock( SubjectRepository::class );
		$subjects->method( 'getByKey' )->willReturn( new SubjectDTO( 'math', 'Математика', false ) );
		$subjects->expects( $this->never() )->method( 'setArchived' );

		$guard = $this->createMock( SubjectArchiveGuard::class );
		$guard->method( 'activeGroups' )->willReturn( array( (object) array( 'id' => 1, 'name' => 'М-101' ) ) );

		$logEvents = $this->createMock( LogEventDispatcherInterface::class );
		$cb        = new SubjectCrudCallbacks( $subjects, $this->dispatcher, $logEvents, $this->groups, $guard );
		$_POST     = array( 'key' => 'math' );

		$r = fs_test_capture_json( fn() => $cb->ajaxToggleSubjectArchive() );

		self::assertFalse( $r->success );
		self::assertStringContainsString( 'активные группы', $r->payload );
	}
}
