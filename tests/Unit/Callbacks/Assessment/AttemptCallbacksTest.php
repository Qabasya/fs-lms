<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Assessment;

use Inc\Callbacks\Assessment\AttemptCallbacks;
use Inc\DTO\Person\PersonDTO;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Services\Assessment\AttemptResultService;
use Inc\Services\Assessment\AttemptService;
use PHPUnit\Framework\TestCase;

class AttemptCallbacksTest extends TestCase {

	private AttemptService       $service;
	private PersonRepository     $persons;
	private AttemptResultService $resultService;
	private AttemptCallbacks     $cb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$this->service       = $this->createMock( AttemptService::class );
		$this->persons       = $this->createMock( PersonRepository::class );
		$this->resultService = $this->createMock( AttemptResultService::class );
		$this->cb            = new AttemptCallbacks( $this->service, $this->persons, $this->resultService );
	}

	private function person( int $id ): PersonDTO {
		return PersonDTO::fromArray( array( 'id' => $id, 'created_at' => '2024-01-01 00:00:00', 'updated_at' => '2024-01-01 00:00:00' ) );
	}

	public function test_save_answer_passes_answer_text_through(): void {
		// Регрессия: answer_text должен дойти до сервиса (был тот же баг sanitizeEditorContent).
		$this->persons->method( 'findByWpUserId' )->willReturn( $this->person( 99 ) );
		$this->service->expects( $this->once() )
			->method( 'saveAnswer' )
			->with( 5, 7, 'Мой ответ', 99 );

		$_POST = array( 'attempt_id' => '5', 'task_id' => '7', 'answer_text' => 'Мой ответ' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxSaveAttemptAnswer() )->success );
	}

	public function test_save_answer_missing_param_errors(): void {
		$this->service->expects( $this->never() )->method( 'saveAnswer' );
		$_POST = array();

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxSaveAttemptAnswer() )->success );
	}

	public function test_start_attempt_profile_not_found_errors(): void {
		$this->persons->method( 'findByWpUserId' )->willReturn( null );
		$this->service->expects( $this->never() )->method( 'start' );
		$_POST = array( 'assessment_id' => '5' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxStartAttempt() )->success );
	}

	public function test_start_attempt_missing_param_errors(): void {
		$this->service->expects( $this->never() )->method( 'start' );
		$_POST = array();

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxStartAttempt() )->success );
	}
}
