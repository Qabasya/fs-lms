<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Log;

use Inc\Callbacks\Log\ExamLogCallbacks;
use Inc\DTO\Assessment\AttemptDTO;
use Inc\Enums\Exam\ExamLogAction;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Services\Log\ExamLogWriter;
use Inc\Services\Security\RateLimitService;
use PHPUnit\Framework\TestCase;

/** Журнал «Экзамены»: приём технических событий станции из браузера. */
class ExamLogCallbacksTest extends TestCase {

	private AssessmentAttemptRepository&\PHPUnit\Framework\MockObject\Stub $attempts;
	private RateLimitService&\PHPUnit\Framework\MockObject\Stub $rateLimit;
	private ExamLogCallbacks $cb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$GLOBALS['_fs_test_actions'] = array();

		$this->attempts  = $this->createStub( AssessmentAttemptRepository::class );
		$this->rateLimit = $this->createStub( RateLimitService::class );
		$this->rateLimit->method( 'allowExamClientEvent' )->willReturn( true );

		$this->cb = new ExamLogCallbacks( $this->createStub( ExamLogWriter::class ), $this->attempts, $this->rateLimit );
	}

	private function attempt( string $status = 'in_progress', ?int $participationId = 20 ): AttemptDTO {
		return AttemptDTO::fromArray( array(
			'id' => 77, 'assessment_id' => 1, 'attempt_number' => 1, 'started_at' => '2026-10-16 09:00:00', 'deadline_at' => '2026-10-16 12:00:00',
			'status' => $status, 'exam_participation_id' => $participationId,
		) );
	}

	/** @return list<array{hook: string, args: array<int, mixed>}> */
	private function logged(): array {
		return array_values( array_filter( $GLOBALS['_fs_test_actions'], static fn ( array $a ): bool => ExamLogAction::HOOK === $a['hook'] ) );
	}

	public function test_valid_event_of_a_running_exam_attempt_is_logged_as_failure(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attempt() );
		$_POST = array( 'attempt_id' => '77', 'kind' => 'left_page', 'detail' => 'pagehide' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxLogExamClientEvent() );

		self::assertTrue( $r->success );
		$logged = $this->logged();
		self::assertCount( 1, $logged );
		self::assertSame( ExamLogAction::AttemptTechError, $logged[0]['args'][0] );
		self::assertSame( 77, $logged[0]['args'][1]['attempt_id'] );
		self::assertSame( 'failure', $logged[0]['args'][1]['result'] );
		self::assertSame( 'left_page', $logged[0]['args'][1]['context']['kind'] );
		// Без входа в WordPress это гость.
		self::assertSame( 'guest', $logged[0]['args'][1]['actor_type'] );
	}

	public function test_unknown_kind_is_ignored_but_answered_with_success(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attempt() );
		$_POST = array( 'attempt_id' => '77', 'kind' => 'delete_everything' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxLogExamClientEvent() )->success );
		self::assertSame( array(), $this->logged() );
	}

	public function test_finished_non_exam_or_missing_attempt_is_ignored(): void {
		$_POST = array( 'attempt_id' => '77', 'kind' => 'left_page' );

		foreach ( array( $this->attempt( 'graded' ), $this->attempt( 'in_progress', null ), null ) as $attempt ) {
			$stub = $this->createStub( AssessmentAttemptRepository::class );
			$stub->method( 'find' )->willReturn( $attempt );
			$cb = new ExamLogCallbacks( $this->createStub( ExamLogWriter::class ), $stub, $this->rateLimit );

			self::assertTrue( fs_test_capture_json( fn() => $cb->ajaxLogExamClientEvent() )->success );
		}
		self::assertSame( array(), $this->logged() );
	}

	public function test_rate_limited_event_is_dropped(): void {
		$limit = $this->createStub( RateLimitService::class );
		$limit->method( 'allowExamClientEvent' )->willReturn( false );
		$this->attempts->method( 'find' )->willReturn( $this->attempt() );
		$cb = new ExamLogCallbacks( $this->createStub( ExamLogWriter::class ), $this->attempts, $limit );
		$_POST = array( 'attempt_id' => '77', 'kind' => 'save_failed' );

		self::assertTrue( fs_test_capture_json( fn() => $cb->ajaxLogExamClientEvent() )->success );
		self::assertSame( array(), $this->logged() );
	}

	public function test_logged_in_student_is_not_marked_as_guest(): void {
		$GLOBALS['_fs_test_user_id'] = 5;
		$this->attempts->method( 'find' )->willReturn( $this->attempt() );
		$_POST = array( 'attempt_id' => '77', 'kind' => 'session_lost' );

		fs_test_capture_json( fn() => $this->cb->ajaxLogExamClientEvent() );

		self::assertArrayNotHasKey( 'actor_type', $this->logged()[0]['args'][1] );
	}
}
