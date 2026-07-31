<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Course;

use Inc\Callbacks\Course\IndividualLessonCallbacks;
use Inc\Services\Course\GroupAccessGuard;
use Inc\Services\Group\IndividualLessonService;
use Inc\Services\Group\ProgramCompositionService;
use PHPUnit\Framework\TestCase;
use Tests\Support\ProgramRowFixtures;

/**
 * Индивидуальные занятия: создание, слоты, назначение урока, правка.
 */
class IndividualLessonCallbacksTest extends TestCase {

	use ProgramRowFixtures;

	private IndividualLessonService   $individual;
	private ProgramCompositionService $program;
	private GroupAccessGuard          $guard;
	private IndividualLessonCallbacks $cb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$this->individual = $this->createMock( IndividualLessonService::class );
		$this->program    = $this->createMock( ProgramCompositionService::class );
		$this->guard      = $this->createMock( GroupAccessGuard::class );

		$this->cb = new IndividualLessonCallbacks( $this->individual, $this->program, $this->guard );
	}

	public function test_create_individual_lesson_delegates_and_returns_id(): void {
		$this->guard->method( 'canManage' )->willReturn( true );
		$this->individual->expects( $this->once() )
			->method( 'createIndividualLesson' )
			->with( 1, 9001, '2026-05-20 15:00:00', null, null, null, null, $this->anything(), null )
			->willReturn( 15 );
		$_POST = array( 'group_id' => '1', 'student_person_id' => '9001', 'scheduled_at' => '2026-05-20 15:00:00' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxCreateIndividualLesson() );

		self::assertTrue( $r->success );
		self::assertSame( 15, $r->payload['group_lesson_id'] );
	}

	public function test_create_individual_lesson_denied_when_not_manager(): void {
		$this->guard->method( 'canManage' )->willReturn( false );
		$this->individual->expects( $this->never() )->method( 'createIndividualLesson' );
		$_POST = array( 'group_id' => '1', 'student_person_id' => '9001', 'scheduled_at' => '2026-05-20 15:00:00' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxCreateIndividualLesson() )->success );
	}

	public function test_create_individual_lesson_surfaces_service_error(): void {
		$this->guard->method( 'canManage' )->willReturn( true );
		$this->individual->method( 'createIndividualLesson' )
			->willThrowException( new \InvalidArgumentException( 'Ученик не состоит в этой группе.' ) );
		$_POST = array( 'group_id' => '1', 'student_person_id' => '9001', 'scheduled_at' => '2026-05-20 15:00:00' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxCreateIndividualLesson() )->success );
	}

	public function test_get_individual_slots_returns_items(): void {
		$this->guard->method( 'canManage' )->willReturn( true );
		$this->individual->method( 'getIndividualProgram' )->with( 1 )
			->willReturn( array( array( 'group_lesson_id' => 15, 'student_name' => 'Антонов Артём' ) ) );
		$_POST = array( 'group_id' => '1' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxGetIndividualSlots() );

		self::assertTrue( $r->success );
		self::assertCount( 1, $r->payload['items'] );
	}

	public function test_update_individual_lesson_passes_only_present_fields(): void {
		$this->program->method( 'getProgramRow' )->willReturn( $this->programRow() );
		$this->guard->method( 'canManage' )->willReturn( true );
		$this->individual->expects( $this->once() )
			->method( 'updateIndividualLesson' )
			->with( 42, null, null, 0, null, null, $this->anything() );
		$_POST = array( 'group_lesson_id' => '42', 'room_id' => '0', 'scheduled_at' => '' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxUpdateIndividualLesson() )->success );
	}

	public function test_assign_individual_lesson_denied_when_row_missing(): void {
		$this->program->method( 'getProgramRow' )->willReturn( null );
		$this->individual->expects( $this->never() )->method( 'assignLessonToIndividual' );
		$_POST = array( 'group_lesson_id' => '42', 'lesson_id' => '10' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxAssignIndividualLesson() )->success );
	}
}
