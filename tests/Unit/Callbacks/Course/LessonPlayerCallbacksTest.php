<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Course;

use Inc\Callbacks\Course\LessonPlayerCallbacks;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\LessonDTO;
use Inc\DTO\Person\PersonDTO;
use Inc\Enums\Course\GateState;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Services\Course\GroupAccessGuard;
use Inc\Services\Course\LessonGateResolver;
use Inc\Services\Course\LessonProgressService;
use PHPUnit\Framework\TestCase;

/**
 * Отметка прогресса шага: только ученик группы, только существующий и доступный шаг
 * (гейт урока, последовательность, экзаменационная блокировка).
 */
class LessonPlayerCallbacksTest extends TestCase {

	private LessonProgressService $progress;
	private LessonPlayerCallbacks $cb;
	private bool                  $isMember  = true;
	private GateState             $gateState = GateState::Available;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$GLOBALS['_fs_test_user_id'] = 7;

		$this->isMember  = true;
		$this->gateState = GateState::Available;

		$persons = $this->createMock( PersonRepository::class );
		$persons->method( 'findByWpUserId' )->willReturn(
			PersonDTO::fromArray( array( 'id' => 99, 'created_at' => '2024-01-01 00:00:00', 'updated_at' => '2024-01-01 00:00:00' ) )
		);

		$groupLessons = $this->createMock( GroupLessonRepository::class );
		$groupLessons->method( 'find' )->willReturnCallback(
			static fn( int $id ) => 5 === $id
				? GroupLessonDTO::fromArray( array( 'id' => 5, 'group_id' => 1, 'lesson_id' => 10, 'position' => 0 ) )
				: null
		);

		$guard = $this->createMock( GroupAccessGuard::class );
		$guard->method( 'isMemberEver' )->willReturnCallback( fn(): bool => $this->isMember );

		$gate = $this->createMock( LessonGateResolver::class );
		$gate->method( 'resolveStep' )->willReturnCallback( fn(): GateState => $this->gateState );

		$lessons = $this->createMock( LessonManager::class );
		$lessons->method( 'get' )->willReturn(
			LessonDTO::fromArray( array( 'id' => 10, 'steps' => array( array( 'key' => 's1', 'type' => 'text', 'payload' => array() ) ) ) )
		);

		$this->progress = $this->createMock( LessonProgressService::class );

		$this->cb = new LessonPlayerCallbacks( $this->progress, $persons, $groupLessons, $guard, $gate, $lessons );

		$_POST = array( 'group_lesson_id' => '5', 'step_key' => 's1', 'status' => 'completed' );
	}

	public function test_member_marks_available_step(): void {
		$this->progress->expects( $this->once() )->method( 'markCompleted' )->with( 99, 5, 's1' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxMarkStepProgress() )->success );
	}

	public function test_non_member_is_rejected(): void {
		$this->isMember = false;
		$this->progress->expects( $this->never() )->method( 'markCompleted' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxMarkStepProgress() )->success );
	}

	public function test_locked_step_is_rejected(): void {
		$this->gateState = GateState::Locked;
		$this->progress->expects( $this->never() )->method( 'markCompleted' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxMarkStepProgress() )->success );
	}

	public function test_unknown_step_is_rejected(): void {
		$_POST['step_key'] = 'ghost';
		$this->progress->expects( $this->never() )->method( 'markCompleted' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxMarkStepProgress() )->success );
	}

	public function test_unknown_group_lesson_is_rejected(): void {
		$_POST['group_lesson_id'] = '404';
		$this->progress->expects( $this->never() )->method( 'markViewed' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxMarkStepProgress() )->success );
	}
}
