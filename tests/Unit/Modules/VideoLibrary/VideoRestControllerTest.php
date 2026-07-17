<?php

declare( strict_types=1 );

namespace Unit\Modules\VideoLibrary;

use Inc\Modules\VideoLibrary\Controllers\VideoRestController;
use Inc\Modules\VideoLibrary\DTO\VideoRecordingInputDTO;
use Inc\Modules\VideoLibrary\Services\VideoHmacAuth;
use Inc\Modules\VideoLibrary\Services\VideoRegistrationService;
use PHPUnit\Framework\TestCase;

class VideoRestControllerTest extends TestCase {

	private VideoRegistrationService $service;
	private VideoRestController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->service    = $this->createMock( VideoRegistrationService::class );
		$this->controller = new VideoRestController( $this->service, $this->createMock( VideoHmacAuth::class ) );
	}

	private function request( array $body ): \WP_REST_Request {
		$request = new \WP_REST_Request();
		$request->set_body( (string) json_encode( $body ) );
		return $request;
	}

	/** @return array<string, mixed> Валидное тело по FS_LMS_API.md §7.1. */
	private function validBody( array $over = array() ): array {
		return array_merge( array(
			's3_bucket'    => 'test-bucket',
			's3_key'       => 'videos/kege-1/2026/07/rec.webm',
			'manifest_key' => 'videos/kege-1/2026/07/rec.webm.json',
			'group_slug'   => 'kege-1',
			'lms'          => array( 'group_id' => 3, 'course_id' => 42, 'teacher_id' => 7 ),
			'recorded_at'  => '2026-07-08T16:04:45+03:00',
			'size_bytes'   => 123456789,
			'sha256'       => str_repeat( 'a', 64 ),
			'duration_sec' => null,
		), $over );
	}

	// ── 200 ──────────────────────────────────────────────────────────────────

	public function test_matched_returns_200_with_lesson_id(): void {
		$this->service->method( 'register' )->willReturn( array(
			'recording_id'    => 10,
			'matched'         => true,
			'group_lesson_id' => 123,
		) );

		$response = $this->controller->postVideo( $this->request( $this->validBody() ) );

		self::assertSame( 200, $response->get_status() );
		self::assertSame(
			array( 'ok' => true, 'matched' => true, 'group_lesson_id' => 123 ),
			$response->get_data()
		);
	}

	public function test_lesson_not_found_is_200_matched_false_not_4xx(): void {
		$this->service->method( 'register' )->willReturn( array(
			'recording_id'    => 10,
			'matched'         => false,
			'group_lesson_id' => null,
		) );

		$response = $this->controller->postVideo( $this->request( $this->validBody() ) );

		self::assertSame( 200, $response->get_status() );
		self::assertSame(
			array( 'ok' => true, 'matched' => false, 'group_lesson_id' => null ),
			$response->get_data()
		);
	}

	public function test_input_dto_is_built_from_body(): void {
		$captured = null;
		$this->service->method( 'register' )->willReturnCallback(
			function ( VideoRecordingInputDTO $input ) use ( &$captured ): array {
				$captured = $input;
				return array( 'recording_id' => 1, 'matched' => false, 'group_lesson_id' => null );
			}
		);

		$this->controller->postVideo( $this->request( $this->validBody() ) );

		self::assertSame( 'test-bucket', $captured->s3Bucket );
		self::assertSame( 3, $captured->groupId );
		self::assertSame( 42, $captured->courseId );
		self::assertSame( 7, $captured->teacherId );
		self::assertNull( $captured->teacherUsername );
		self::assertSame( '2026-07-08T16:04:45+03:00', $captured->recordedAt );
		self::assertSame( 123456789, $captured->sizeBytes );
		self::assertStringContainsString( '"s3_key"', $captured->payload );
	}

	public function test_teacher_username_branch_is_accepted(): void {
		$this->service->method( 'register' )->willReturn( array(
			'recording_id'    => 1,
			'matched'         => false,
			'group_lesson_id' => null,
		) );

		$body = $this->validBody( array( 'lms' => array( 'teacher_username' => 'i.petrov' ) ) );

		self::assertSame( 200, $this->controller->postVideo( $this->request( $body ) )->get_status() );
	}

	// ── 400 ──────────────────────────────────────────────────────────────────

	public function test_missing_s3_key_is_400(): void {
		$body = $this->validBody();
		unset( $body['s3_key'] );

		$response = $this->controller->postVideo( $this->request( $body ) );

		self::assertSame( 400, $response->get_status() );
		self::assertFalse( $response->get_data()['ok'] );
	}

	public function test_invalid_recorded_at_is_400(): void {
		$response = $this->controller->postVideo(
			$this->request( $this->validBody( array( 'recorded_at' => 'вчера вечером' ) ) )
		);

		self::assertSame( 400, $response->get_status() );
	}

	public function test_lms_block_without_group_and_teacher_is_400(): void {
		$response = $this->controller->postVideo(
			$this->request( $this->validBody( array( 'lms' => array( 'course_id' => 42 ) ) ) )
		);

		self::assertSame( 400, $response->get_status() );
	}

	public function test_non_flat_lms_block_is_400(): void {
		$response = $this->controller->postVideo(
			$this->request( $this->validBody( array( 'lms' => array( 'group_id' => array( 3 ) ) ) ) )
		);

		self::assertSame( 400, $response->get_status() );
	}

	public function test_empty_body_is_400(): void {
		$request = new \WP_REST_Request();
		$request->set_body( '' );

		self::assertSame( 400, $this->controller->postVideo( $request )->get_status() );
	}

	public function test_service_invalid_argument_maps_to_400(): void {
		$this->service->method( 'register' )
			->willThrowException( new \InvalidArgumentException( 'bad recorded_at' ) );

		$response = $this->controller->postVideo( $this->request( $this->validBody() ) );

		self::assertSame( 400, $response->get_status() );
		self::assertSame( 'bad recorded_at', $response->get_data()['error'] );
	}
}
