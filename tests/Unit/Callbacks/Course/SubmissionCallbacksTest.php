<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Course;

use Inc\Callbacks\Course\SubmissionCallbacks;
use Inc\DTO\Assessment\AttemptDTO;
use Inc\DTO\Person\PersonDTO;
use Inc\Managers\Wp\MediaManager;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use PHPUnit\Framework\TestCase;

class SubmissionCallbacksTest extends TestCase {

	private PersonRepository            $persons;
	private MediaManager                $media;
	private AssessmentAttemptRepository $attempts;
	private SubmissionCallbacks         $cb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$this->persons  = $this->createMock( PersonRepository::class );
		$this->media    = $this->createMock( MediaManager::class );
		$this->attempts = $this->createMock( AssessmentAttemptRepository::class );
		$this->cb       = new SubmissionCallbacks( $this->persons, $this->media, $this->attempts );
	}

	private function person( int $id ): PersonDTO {
		return PersonDTO::fromArray( array( 'id' => $id, 'created_at' => '2024-01-01 00:00:00', 'updated_at' => '2024-01-01 00:00:00' ) );
	}

	private function attempt( int $id, int $studentPersonId ): AttemptDTO {
		return AttemptDTO::fromArray( array(
			'id'                => $id,
			'assessment_id'     => 1,
			'student_person_id' => $studentPersonId,
			'group_id'          => null,
			'attempt_number'    => 1,
			'started_at'        => '2026-01-01 00:00:00',
			'deadline_at'       => '2026-01-01 01:00:00',
			'status'            => 'in_progress',
		) );
	}

	/* ── Двухшаговая загрузка файла ответа для «Развёрнутого ответа» (Эпик 13, D16) ── */

	public function test_upload_answer_file_allows_own_attempt(): void {
		$this->persons->method( 'findByWpUserId' )->willReturn( $this->person( 99 ) );
		$this->attempts->method( 'find' )->with( 7 )->willReturn( $this->attempt( 7, 99 ) );
		$this->media->method( 'uploadFromRequest' )->with( 'answer_file' )->willReturn( 502 );
		$this->media->method( 'url' )->with( 502 )->willReturn( 'https://example.test/solution.py' );
		$GLOBALS['_fs_test_post_mime_types'][502] = 'text/x-python';
		$_POST = array( 'attempt_id' => '7' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxUploadAnswerFile() );

		self::assertTrue( $r->success );
		self::assertSame( 502, $r->payload['attachment_id'] );
		self::assertSame( 'https://example.test/solution.py', $r->payload['url'] );
		self::assertSame( 'text/x-python', $r->payload['mime'] );
	}

	public function test_upload_answer_file_denied_for_foreign_attempt(): void {
		$this->persons->method( 'findByWpUserId' )->willReturn( $this->person( 99 ) );
		$this->attempts->method( 'find' )->willReturn( $this->attempt( 7, 12345 ) ); // чужая попытка
		$this->media->expects( $this->never() )->method( 'uploadFromRequest' );
		$_POST = array( 'attempt_id' => '7' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxUploadAnswerFile() )->success );
	}

	public function test_upload_answer_file_denied_when_attempt_missing(): void {
		$this->persons->method( 'findByWpUserId' )->willReturn( $this->person( 99 ) );
		$this->attempts->method( 'find' )->willReturn( null );
		$this->media->expects( $this->never() )->method( 'uploadFromRequest' );
		$_POST = array( 'attempt_id' => '999' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxUploadAnswerFile() )->success );
	}

	public function test_upload_answer_file_surfaces_media_manager_error(): void {
		$this->persons->method( 'findByWpUserId' )->willReturn( $this->person( 99 ) );
		$this->attempts->method( 'find' )->willReturn( $this->attempt( 7, 99 ) );
		$this->media->method( 'uploadFromRequest' )
			->willThrowException( new \RuntimeException( 'Файл превышает допустимый размер 20 МБ.' ) );
		$_POST = array( 'attempt_id' => '7' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxUploadAnswerFile() )->success );
	}
}
