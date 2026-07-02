<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Course;

use Inc\Callbacks\Course\SubmissionCallbacks;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Person\PersonDTO;
use Inc\Managers\Wp\MediaManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Services\Course\GroupAccessGuard;
use Inc\Services\Course\SubmissionService;
use PHPUnit\Framework\TestCase;

class SubmissionCallbacksTest extends TestCase {

	private SubmissionService     $service;
	private PersonRepository      $persons;
	private GroupAccessGuard      $guard;
	private GroupLessonRepository $groupLessons;
	private MediaManager          $media;
	private SubmissionCallbacks   $cb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$this->service      = $this->createMock( SubmissionService::class );
		$this->persons      = $this->createMock( PersonRepository::class );
		$this->guard        = $this->createMock( GroupAccessGuard::class );
		$this->groupLessons = $this->createMock( GroupLessonRepository::class );
		$this->media        = $this->createMock( MediaManager::class );
		$this->cb           = new SubmissionCallbacks(
			$this->service, $this->persons, $this->guard, $this->groupLessons, $this->media
		);
	}

	private function person( int $id ): PersonDTO {
		return PersonDTO::fromArray( array( 'id' => $id, 'created_at' => '2024-01-01 00:00:00', 'updated_at' => '2024-01-01 00:00:00' ) );
	}

	private function row( int $id = 5, int $groupId = 1 ): GroupLessonDTO {
		return new GroupLessonDTO(
			id: $id, groupId: $groupId, lessonId: null, position: 0, workIdsSnapshot: null, extraWorkIds: array(),
			scheduledAt: null, endsAt: null, isPinned: false, teacherUserId: null, visibility: 'open',
			openedAt: null, homeworkDueAt: null, allowLate: true, recordingUrl: null,
			createdByUserId: null, updatedByUserId: null,
		);
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

	/* ── Двухшаговая загрузка файла ответа (Эпик 13, D16) ────────────────── */

	public function test_upload_answer_file_returns_attachment_payload(): void {
		$this->persons->method( 'findByWpUserId' )->willReturn( $this->person( 99 ) );
		$this->groupLessons->method( 'find' )->with( 5 )->willReturn( $this->row( 5, 1 ) );
		$this->guard->method( 'isMemberEver' )->with( 1, 99 )->willReturn( true );
		$this->media->method( 'uploadFromRequest' )->with( 'answer_file' )->willReturn( 501 );
		$this->media->method( 'url' )->with( 501 )->willReturn( 'https://example.test/solution.jpg' );
		$GLOBALS['_fs_test_post_mime_types'][501] = 'image/jpeg';
		$_POST = array( 'group_lesson_id' => '5' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxUploadAnswerFile() );

		self::assertTrue( $r->success );
		self::assertSame( 501, $r->payload['attachment_id'] );
		self::assertSame( 'https://example.test/solution.jpg', $r->payload['url'] );
		self::assertSame( 'image/jpeg', $r->payload['mime'] );
	}

	public function test_upload_answer_file_denied_for_non_member(): void {
		$this->persons->method( 'findByWpUserId' )->willReturn( $this->person( 99 ) );
		$this->groupLessons->method( 'find' )->willReturn( $this->row( 5, 1 ) );
		$this->guard->method( 'isMemberEver' )->willReturn( false );
		$this->media->expects( $this->never() )->method( 'uploadFromRequest' );
		$_POST = array( 'group_lesson_id' => '5' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxUploadAnswerFile() )->success );
	}

	public function test_upload_answer_file_denied_when_lesson_missing(): void {
		$this->persons->method( 'findByWpUserId' )->willReturn( $this->person( 99 ) );
		$this->groupLessons->method( 'find' )->willReturn( null );
		$this->media->expects( $this->never() )->method( 'uploadFromRequest' );
		$_POST = array( 'group_lesson_id' => '999' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxUploadAnswerFile() )->success );
	}

	public function test_upload_answer_file_surfaces_media_manager_error(): void {
		$this->persons->method( 'findByWpUserId' )->willReturn( $this->person( 99 ) );
		$this->groupLessons->method( 'find' )->willReturn( $this->row( 5, 1 ) );
		$this->guard->method( 'isMemberEver' )->willReturn( true );
		$this->media->method( 'uploadFromRequest' )
			->willThrowException( new \RuntimeException( 'Файл превышает допустимый размер 20 МБ.' ) );
		$_POST = array( 'group_lesson_id' => '5' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxUploadAnswerFile() )->success );
	}
}
