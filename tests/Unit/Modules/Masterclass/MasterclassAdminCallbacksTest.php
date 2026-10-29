<?php

declare( strict_types=1 );

namespace Unit\Modules\Masterclass;

use Inc\Contracts\ClockInterface;
use Inc\Enums\Access\Capability;
use Inc\Modules\Masterclass\Callbacks\MasterclassAdminCallbacks;
use Inc\Modules\Masterclass\DTO\MasterclassDTO;
use Inc\Modules\Masterclass\DTO\MasterclassFlyerDTO;
use Inc\Modules\Masterclass\DTO\MasterclassInputDTO;
use Inc\Modules\Masterclass\Repositories\MasterclassAccountRepository;
use Inc\Modules\Masterclass\Services\MasterclassAccountService;
use Inc\Modules\Masterclass\Services\MasterclassService;
use Inc\Services\Exam\ExamTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * AJAX и печать флаеров страницы модуля: право и nonce на каждом входе, ввод через Sanitizer, ответы, отказ по исключению сервиса.
 */
class MasterclassAdminCallbacksTest extends TestCase {

	private MasterclassService&MockObject $service;
	private MasterclassAccountService&MockObject $accounts;
	private MasterclassAccountRepository&MockObject $accountRows;
	private MasterclassAdminCallbacks $cb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$GLOBALS['_fs_test_user_id']  = 501;
		$GLOBALS['_fs_test_timezone'] = 'Europe/Moscow';
		$_POST = $_GET = array();

		$this->service     = $this->createMock( MasterclassService::class );
		$this->accounts    = $this->createMock( MasterclassAccountService::class );
		$this->accountRows = $this->createMock( MasterclassAccountRepository::class );

		$clock = $this->createStub( ClockInterface::class );
		$clock->method( 'now' )->willReturn( '2026-11-10 05:00:00' );

		$this->cb = new MasterclassAdminCallbacks( $this->service, $this->accounts, $this->accountRows, new ExamTime( $clock ) );
	}

	protected function tearDown(): void {
		$_POST = $_GET = array();
		unset( $GLOBALS['_fs_test_timezone'], $GLOBALS['_fs_test_can_callback'] );
		parent::tearDown();
	}

	/** @param array<string, string> $post */
	private function call( string $method, array $post = array() ): \FsTestJsonResponse {
		$_POST = $post;

		return fs_test_capture_json( fn() => $this->cb->$method() );
	}

	private function masterclass( int $id = 7 ): MasterclassDTO {
		return MasterclassDTO::fromArray( array(
			'id' => $id, 'school_name' => 'Школа №5', 'subject_key' => 'inf', 'lesson_id' => 11, 'group_id' => 31, 'group_lesson_id' => 41,
			'teacher_user_id' => 3, 'starts_at' => '2026-11-12 06:00:00', 'ends_at' => '2026-11-12 11:00:00',
			'freeze_at' => '2026-11-26 11:00:00', 'unused_purge_at' => '2026-11-19 11:00:00', 'purge_at' => '2026-12-26 11:00:00',
			'created_at' => '2026-11-01 00:00:00', 'updated_at' => '2026-11-01 00:00:00',
		) );
	}

	/** @return array<string, string> */
	private function formPost( array $over = array() ): array {
		return array_merge(
			array(
				'school_name'     => 'Школа №5',
				'subject_key'     => 'inf',
				'lesson_id'       => '11',
				'teacher_user_id' => '3',
				'starts_at'       => '2026-11-12 09:00:00',
				'ends_at'         => '2026-11-12 14:00:00',
			),
			$over
		);
	}

	/** @return array<string, array{0: string, 1: array<string, string>}> */
	public static function ajaxEntries(): array {
		return array(
			'create'      => array( 'ajaxCreate', array( 'school_name' => 'Школа', 'subject_key' => 'inf', 'lesson_id' => '1', 'teacher_user_id' => '3', 'starts_at' => 'x', 'ends_at' => 'y' ) ),
			'update'      => array( 'ajaxUpdate', array( 'id' => '7', 'school_name' => 'Школа', 'subject_key' => 'inf', 'lesson_id' => '1', 'teacher_user_id' => '3', 'starts_at' => 'x', 'ends_at' => 'y' ) ),
			'extend'      => array( 'ajaxExtend', array( 'id' => '7', 'days' => '5' ) ),
			'delete'      => array( 'ajaxDelete', array( 'id' => '7' ) ),
			'add'         => array( 'ajaxAddAccounts', array( 'id' => '7', 'count' => '5' ) ),
			'unlock'      => array( 'ajaxUnlock', array( 'id' => '7' ) ),
			'lessons'     => array( 'ajaxLessons', array( 'subject_key' => 'inf' ) ),
		);
	}

	/** @param array<string, string> $post */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'ajaxEntries' )]
	public function test_every_entry_refuses_without_the_capability_and_touches_no_service( string $method, array $post ): void {
		$GLOBALS['_fs_test_can'] = false;
		$this->service->expects( self::never() )->method( self::anything() );
		$this->accounts->expects( self::never() )->method( self::anything() );

		$response = $this->call( $method, $post );

		self::assertFalse( $response->success );
	}

	/** @param array<string, string> $post */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'ajaxEntries' )]
	public function test_every_entry_refuses_with_a_bad_nonce_and_touches_no_service( string $method, array $post ): void {
		$GLOBALS['_fs_test_nonce_ok'] = false;
		$this->service->expects( self::never() )->method( self::anything() );
		$this->accounts->expects( self::never() )->method( self::anything() );

		$response = $this->call( $method, $post );

		self::assertFalse( $response->success );
	}

	/** @param array<string, string> $post */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'ajaxEntries' )]
	public function test_every_entry_demands_the_platform_capability_and_no_other( string $method, array $post ): void {
		// Разрешено всё, кроме права платформы: вход, спрашивающий другое право, пропустил бы запрос.
		$GLOBALS['_fs_test_can_callback'] = static fn ( $cap ): bool => Capability::ManageLmsPlatform->value !== $cap;
		$this->service->expects( self::never() )->method( self::anything() );
		$this->accounts->expects( self::never() )->method( self::anything() );

		$response = $this->call( $method, $post );

		self::assertFalse( $response->success );
	}

	public function test_create_passes_the_form_to_the_service_and_answers_with_the_id(): void {
		$input = null;
		$this->service->expects( self::once() )->method( 'create' )->willReturnCallback(
			function ( MasterclassInputDTO $dto, int $actor ) use ( &$input ): MasterclassDTO {
				$input = array( $dto, $actor );
				return $this->masterclass( 7 );
			}
		);

		$response = $this->call( 'ajaxCreate', $this->formPost() );

		self::assertTrue( $response->success );
		self::assertSame( array( 'id' => 7 ), $response->payload );
		self::assertEquals( new MasterclassInputDTO( 'Школа №5', 'inf', 11, 3, '2026-11-12 09:00:00', '2026-11-12 14:00:00' ), $input[0] );
		self::assertSame( 501, $input[1], 'актор — пользователь запроса' );
	}

	public function test_create_without_a_school_is_refused_before_the_service(): void {
		$this->service->expects( self::never() )->method( 'create' );

		$response = $this->call( 'ajaxCreate', $this->formPost( array( 'school_name' => '' ) ) );

		self::assertFalse( $response->success );
		self::assertStringContainsString( 'школ', mb_strtolower( (string) $response->payload ) );
	}

	public function test_a_service_refusal_reaches_the_user_as_its_own_message(): void {
		$this->service->method( 'create' )->willThrowException( new \InvalidArgumentException( 'Урок другого предмета.' ) );

		$response = $this->call( 'ajaxCreate', $this->formPost() );

		self::assertFalse( $response->success );
		self::assertSame( 'Урок другого предмета.', $response->payload );
	}

	public function test_a_storage_failure_is_reported_not_thrown(): void {
		$this->service->method( 'create' )->willThrowException( new \RuntimeException( 'Не удалось создать группу мастер-класса.' ) );

		$response = $this->call( 'ajaxCreate', $this->formPost() );

		self::assertFalse( $response->success );
		self::assertSame( 'Не удалось создать группу мастер-класса.', $response->payload );
	}

	public function test_a_storage_failure_is_written_to_the_php_log_but_a_plain_refusal_is_not(): void {
		$file = tempnam( sys_get_temp_dir(), 'zz_mk_log' );
		$old  = ini_set( 'error_log', $file );

		try {
			$this->service->method( 'create' )->willThrowException( new \RuntimeException( 'Не удалось создать группу мастер-класса.' ) );
			$this->call( 'ajaxCreate', $this->formPost() );
			$afterStorageFailure = (string) file_get_contents( $file );

			$this->service = $this->createMock( MasterclassService::class );
			$this->service->method( 'create' )->willThrowException( new \InvalidArgumentException( 'Укажите школу.' ) );
			$this->cb = new MasterclassAdminCallbacks( $this->service, $this->accounts, $this->accountRows, new ExamTime( $this->createStub( ClockInterface::class ) ) );
			file_put_contents( $file, '' );
			$this->call( 'ajaxCreate', $this->formPost() );
			$afterRefusal = (string) file_get_contents( $file );
		} finally {
			ini_set( 'error_log', (string) $old );
			unlink( $file );
		}

		self::assertStringContainsString( 'Не удалось создать группу мастер-класса.', $afterStorageFailure );
		self::assertStringNotContainsString( 'Укажите школу.', $afterRefusal, 'обычный отказ — не авария, в журнал PHP не идёт' );
	}

	public function test_update_needs_the_id_and_passes_it_on(): void {
		$this->service->expects( self::once() )->method( 'update' )->with( 7, self::isInstanceOf( MasterclassInputDTO::class ), 501 )->willReturn( $this->masterclass() );

		$ok = $this->call( 'ajaxUpdate', $this->formPost( array( 'id' => '7' ) ) );
		self::assertTrue( $ok->success );
		self::assertSame( array( 'id' => 7 ), $ok->payload );

		$this->service = $this->createMock( MasterclassService::class );
		$this->service->expects( self::never() )->method( 'update' );
		$this->cb = new MasterclassAdminCallbacks( $this->service, $this->accounts, $this->accountRows, new ExamTime( $this->createStub( ClockInterface::class ) ) );

		self::assertFalse( $this->call( 'ajaxUpdate', $this->formPost() )->success, 'без id — отказ' );
	}

	public function test_extend_answers_with_local_times(): void {
		$this->service->expects( self::once() )->method( 'extend' )->with( 7, 5, 501 )->willReturn( $this->masterclass() );

		$response = $this->call( 'ajaxExtend', array( 'id' => '7', 'days' => '5' ) );

		self::assertTrue( $response->success );
		self::assertSame( array( 'freeze_at' => '2026-11-26 14:00:00', 'purge_at' => '2026-12-26 14:00:00' ), $response->payload );
	}

	public function test_delete_reports_how_many_accounts_will_be_detached(): void {
		$this->service->expects( self::once() )->method( 'requestDelete' )->with( 7, 501 )->willReturn( 2 );

		$response = $this->call( 'ajaxDelete', array( 'id' => '7' ) );

		self::assertTrue( $response->success );
		self::assertSame( array( 'detached' => 2 ), $response->payload );
	}

	public function test_add_accounts_creates_one_chunk_and_reports_created_and_total(): void {
		$this->accounts->expects( self::once() )->method( 'createChunk' )->with( 7, 25, 501 )->willReturn( 24 );
		$this->accountRows->method( 'countLive' )->with( 7 )->willReturn( 49 );

		$response = $this->call( 'ajaxAddAccounts', array( 'id' => '7', 'count' => '25' ) );

		self::assertTrue( $response->success );
		self::assertSame( array( 'created' => 24, 'total' => 49 ), $response->payload );
	}

	public function test_add_accounts_refusal_reaches_the_user(): void {
		$this->accounts->method( 'createChunk' )->willThrowException( new \InvalidArgumentException( 'За один раз можно создать от 1 до 25 аккаунтов.' ) );

		$response = $this->call( 'ajaxAddAccounts', array( 'id' => '7', 'count' => '99' ) );

		self::assertFalse( $response->success );
		self::assertSame( 'За один раз можно создать от 1 до 25 аккаунтов.', $response->payload );
	}

	public function test_unlock_reports_the_number_of_unlocked_accounts(): void {
		$this->accounts->expects( self::once() )->method( 'unlockLogins' )->with( 7, 501 )->willReturn( 60 );

		$response = $this->call( 'ajaxUnlock', array( 'id' => '7' ) );

		self::assertTrue( $response->success );
		self::assertSame( array( 'unlocked' => 60 ), $response->payload );
	}

	public function test_lessons_are_listed_for_the_subject(): void {
		$options = array( array( 'id' => 11, 'title' => 'Системы счисления', 'problems' => array() ) );
		$this->service->expects( self::once() )->method( 'lessonOptions' )->with( 'inf' )->willReturn( $options );

		$response = $this->call( 'ajaxLessons', array( 'subject_key' => 'inf' ) );

		self::assertTrue( $response->success );
		self::assertSame( $options, $response->payload );
	}

	// ─── печать флаеров ────────────────────────────────────────────────────

	public function test_flyers_without_the_capability_print_nothing_and_ask_the_service_nothing(): void {
		$GLOBALS['_fs_test_can'] = false;
		$_GET                    = array( 'id' => '7', 'security' => 'n' );
		$this->service->method( 'find' )->willReturn( $this->masterclass() );
		$this->accounts->expects( self::never() )->method( 'flyers' );

		$this->expectException( \FsTestWpDie::class );
		$this->cb->printFlyers();
	}

	public function test_flyers_with_a_bad_nonce_print_nothing(): void {
		$GLOBALS['_fs_test_nonce_ok']   = false;
		$GLOBALS['_fs_test_doing_ajax'] = false;
		$_GET                           = array( 'id' => '7', 'security' => 'n' );
		$this->service->method( 'find' )->willReturn( $this->masterclass() );
		$this->accounts->expects( self::never() )->method( 'flyers' );

		try {
			$this->cb->printFlyers();
			self::fail( 'ожидался отказ' );
		} catch ( \FsTestWpDie ) {
			$this->addToAssertionCount( 1 );
		} finally {
			unset( $GLOBALS['_fs_test_doing_ajax'] );
		}
	}

	public function test_the_flyers_document_lists_the_login_password_and_school_of_every_flyer(): void {
		$_GET = array( 'id' => '7' );
		$this->service->method( 'find' )->willReturn( $this->masterclass() );
		$this->accounts->expects( self::once() )->method( 'flyers' )->with( 7, 501 )->willReturn( array(
			new MasterclassFlyerDTO( 15, 'student-15', 'Abcd2345', 'Школа №5', 'https://example.org/sign-in/' ),
			new MasterclassFlyerDTO( 16, 'student-16', 'Wxyz6789', 'Школа №5', 'https://example.org/sign-in/' ),
		) );

		$html = $this->cb->flyersDocument( 7 );

		self::assertStringStartsWith( '<!doctype html>', strtolower( ltrim( $html ) ) );
		foreach ( array( 'student-15', 'Abcd2345', 'student-16', 'Wxyz6789', 'Школа №5', 'https://example.org/sign-in/' ) as $needle ) {
			self::assertStringContainsString( $needle, $html );
		}
		self::assertSame( 2, substr_count( $html, 'class="flyer"' ), 'по флаеру на аккаунт' );
		self::assertStringNotContainsString( 'style="', $html, 'inline-стилей нет' );
	}

	public function test_flyers_of_a_missing_masterclass_end_with_a_message_not_a_fatal(): void {
		$_GET = array( 'id' => '99' );
		$this->service->method( 'find' )->willReturn( null );
		$this->accounts->expects( self::never() )->method( 'flyers' );

		$this->expectException( \InvalidArgumentException::class );
		$this->cb->flyersDocument( 99 );
	}

	public function test_an_empty_flyer_list_says_so_instead_of_printing_a_blank_sheet(): void {
		$this->service->method( 'find' )->willReturn( $this->masterclass() );
		$this->accounts->method( 'flyers' )->willReturn( array() );

		$html = $this->cb->flyersDocument( 7 );

		self::assertStringNotContainsString( 'class="flyer"', $html );
		self::assertStringContainsString( 'Нет аккаунтов', $html );
	}
}
