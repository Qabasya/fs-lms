<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Course;

use Inc\Callbacks\Course\SubmissionCallbacks;
use Inc\DTO\Person\PersonDTO;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Services\Course\GroupAccessGuard;
use Inc\Services\Course\SubmissionService;
use PHPUnit\Framework\TestCase;

class SubmissionCallbacksTest extends TestCase {

	private SubmissionService   $service;
	private PersonRepository    $persons;
	private SubmissionCallbacks $cb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$this->service = $this->createMock( SubmissionService::class );
		$this->persons = $this->createMock( PersonRepository::class );
		$this->cb      = new SubmissionCallbacks( $this->service, $this->persons, $this->createMock( GroupAccessGuard::class ) );
	}

	private function person( int $id ): PersonDTO {
		return PersonDTO::fromArray( array( 'id' => $id, 'created_at' => '2024-01-01 00:00:00', 'updated_at' => '2024-01-01 00:00:00' ) );
	}

	public function test_submit_work_passes_answer_text_through(): void {
		// Регрессия: answer_text должен реально дойти до сервиса (был баг — sanitizeEditorContent терял его).
		$this->persons->method( 'findByWpUserId' )->willReturn( $this->person( 99 ) );
		$this->service->expects( $this->once() )
			->method( 'submit' )
			->with( 99, 5, 7, null, 'Ответ ученика', null )
			->willReturn( 10 );

		$_POST = array( 'group_lesson_id' => '5', 'work_id' => '7', 'answer_text' => 'Ответ ученика' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxSubmitWork() );

		self::assertTrue( $r->success );
		self::assertSame( 10, $r->payload['submission_id'] );
	}

	public function test_submit_work_profile_not_found_errors(): void {
		$this->persons->method( 'findByWpUserId' )->willReturn( null );
		$this->service->expects( $this->never() )->method( 'submit' );
		$_POST = array( 'group_lesson_id' => '5', 'work_id' => '7' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxSubmitWork() )->success );
	}

	public function test_submit_work_missing_param_errors(): void {
		$this->service->expects( $this->never() )->method( 'submit' );
		$_POST = array();

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxSubmitWork() )->success );
	}

	public function test_submit_work_invalid_nonce_errors(): void {
		$GLOBALS['_fs_test_nonce_ok'] = false;
		$this->service->expects( $this->never() )->method( 'submit' );
		$_POST = array( 'group_lesson_id' => '5', 'work_id' => '7' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxSubmitWork() )->success );
	}

	public function test_get_my_submissions_returns_list(): void {
		$this->persons->method( 'findByWpUserId' )->willReturn( $this->person( 99 ) );
		$this->service->method( 'getSubmissionsForView' )->willReturn( array() );
		$_POST = array( 'group_lesson_id' => '5' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxGetMySubmissions() );

		self::assertTrue( $r->success );
		self::assertSame( array(), $r->payload );
	}
}
