<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Exam;

use Inc\Callbacks\Exam\ExamSourceCallbacks;
use Inc\DTO\Exam\ExamSourceDTO;
use Inc\Enums\Log\ErrorCode;
use Inc\Services\Exam\ExamSourceService;
use Inc\Shared\CodedException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * AJAX источников приглашений: права гостевой записи, список без ключей и хешей, ключ — один раз в ответе выдачи.
 */
#[AllowMockObjectsWithoutExpectations]
class ExamSourceCallbacksTest extends TestCase {

	private const KEY = 'a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4';

	private ExamSourceService&MockObject $sources;
	private ExamSourceCallbacks $cb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$GLOBALS['_fs_test_user_id'] = 10;

		$this->sources = $this->createMock( ExamSourceService::class );
		$this->cb      = new ExamSourceCallbacks( $this->sources );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_fs_test_user_id'], $GLOBALS['_fs_test_caps_checked'] );
		parent::tearDown();
	}

	/** @return array<string, mixed> */
	private function listRow(): array {
		return array( 'id' => 14, 'school_name' => 'Школа 5', 'teacher_name' => 'Иванова И. И.', 'grade' => 11, 'is_active' => true, 'has_link' => true, 'generation' => 2, 'active_holds' => 0, 'version' => 3 );
	}

	public function test_list_never_contains_key_or_hash(): void {
		$this->sources->method( 'list' )->with( 10, 3 )->willReturn( array( $this->listRow() ) );
		$_POST = array( 'event_id' => '3' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxGetExamSources() );

		self::assertTrue( $r->success );
		$json = (string) json_encode( $r->payload );
		self::assertStringNotContainsString( 'token_hash', $json );
		self::assertStringNotContainsString( 'plain', $json );
		self::assertStringNotContainsString( '"url"', $json );
		self::assertTrue( $r->payload['sources'][0]['has_link'] );
	}

	public function test_issue_returns_url_once(): void {
		$url = 'http://example.com/exam-signup/?k=' . self::KEY;
		$this->sources->expects( self::once() )->method( 'issueLink' )->with( 10, 14 )->willReturn( $url );
		$_POST = array( 'source_id' => '14' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxIssueExamSourceLink() );

		self::assertTrue( $r->success );
		self::assertSame( $url, $r->payload['url'] );
	}

	public function test_reissue_returns_new_url(): void {
		$this->sources->expects( self::once() )->method( 'reissueLink' )->with( 10, 14 )->willReturn( 'http://example.com/exam-signup/?k=' . self::KEY );
		$_POST = array( 'source_id' => '14' );

		self::assertStringContainsString( self::KEY, fs_test_capture_json( fn() => $this->cb->ajaxReissueExamSourceLink() )->payload['url'] );
	}

	public function test_revoke_and_toggle_do_not_return_a_key(): void {
		$this->sources->expects( self::once() )->method( 'revokeLink' )->with( 10, 14 );
		$_POST = array( 'source_id' => '14' );
		$revoked = fs_test_capture_json( fn() => $this->cb->ajaxRevokeExamSourceLink() );
		self::assertTrue( $revoked->success );
		self::assertArrayNotHasKey( 'url', $revoked->payload );

		$this->sources->expects( self::once() )->method( 'setActive' )->with( 10, 14, false );
		$_POST = array( 'source_id' => '14', 'active' => '0' );
		$toggled = fs_test_capture_json( fn() => $this->cb->ajaxToggleExamSource() );
		self::assertTrue( $toggled->success );
		self::assertArrayNotHasKey( 'url', $toggled->payload );
	}

	public function test_actions_do_not_require_export_pii(): void {
		// Право на гостей достаточно: выдача и копирование ссылок не требуют ExportPII и ManageLmsPlatform.
		$requested = array();
		$GLOBALS['_fs_test_can_callback'] = static function ( string $cap ) use ( &$requested ): bool {
			$requested[] = $cap;
			return 'manage_lms_exam_guests' === $cap;
		};
		$this->sources->method( 'issueLink' )->willReturn( 'http://example.com/exam-signup/?k=' . self::KEY );
		$_POST = array( 'source_id' => '14' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxIssueExamSourceLink() );
		unset( $GLOBALS['_fs_test_can_callback'] );

		self::assertTrue( $r->success );
		self::assertSame( array( 'manage_lms_exam_guests' ), array_values( array_unique( $requested ) ) );
	}

	public function test_denied_without_manage_exam_guests(): void {
		$GLOBALS['_fs_test_can'] = false;
		$this->sources->expects( self::never() )->method( 'issueLink' );
		$this->sources->expects( self::never() )->method( 'list' );
		$_POST = array( 'source_id' => '14', 'event_id' => '3' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxIssueExamSourceLink() )->success );
		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxGetExamSources() )->success );
	}

	public function test_service_refusal_comes_back_with_code(): void {
		$this->sources->method( 'issueLink' )->willThrowException( new CodedException( ErrorCode::ExamConflict, 'Ссылка уже создана: чтобы заменить её, перевыпустите.' ) );
		$_POST = array( 'source_id' => '14' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxIssueExamSourceLink() );

		self::assertFalse( $r->success );
		self::assertSame( 'X-CONFLICT', $r->payload['code'] );
	}

	public function test_save_passes_form_and_returns_refreshed_list(): void {
		$source = ExamSourceDTO::fromArray( array(
			'id' => '14', 'event_id' => '3', 'school_name' => 'Школа 5', 'school_name_normalized' => 'школа 5', 'grade' => '11', 'teacher_name' => 'Иванова И. И.',
			'label' => '', 'is_active' => '1', 'key_generation' => '0', 'created_by_user_id' => '10', 'version' => '1', 'created_at' => '2026-03-01 00:00:00', 'updated_at' => '2026-03-01 00:00:00',
		) );
		$this->sources->expects( self::once() )->method( 'save' )->with(
			10,
			3,
			array( 'school_name' => 'Школа 5', 'school_key' => 'school-1a2b3c4d', 'teacher_name' => 'Иванова И. И.', 'grade' => 11 ),
			null,
			null
		)->willReturn( $source );
		$this->sources->method( 'list' )->willReturn( array( $this->listRow() ) );
		$_POST = array( 'event_id' => '3', 'school_name' => 'Школа 5', 'school_key' => 'school-1a2b3c4d', 'teacher_name' => 'Иванова И. И.', 'grade' => '11' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxSaveExamSource() );

		self::assertTrue( $r->success );
		self::assertSame( 14, $r->payload['source_id'] );
		self::assertCount( 1, $r->payload['sources'] );
	}
}
