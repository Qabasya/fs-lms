<?php

declare( strict_types=1 );

namespace Unit\Services\Log;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Log\AuthLogInputDTO;
use Inc\Enums\Auth\AuthAction;
use Inc\Enums\Auth\AuthResult;
use Inc\Repositories\WPDBRepositories\Log\AuthLogRepository;
use Inc\Services\Log\AuthLogWriter;
use PHPUnit\Framework\TestCase;

class AuthLogWriterTest extends TestCase {
	public function test_request_correlation_and_sensitive_values_are_not_logged(): void {
		$server = $_SERVER;
		$post = $_POST;
		try {
			$_SERVER['REQUEST_METHOD'] = 'POST';
			$_SERVER['REQUEST_URI'] = '/wp-login.php?secret=hidden';
			$_SERVER['HTTP_REFERER'] = 'https://example.test/sign-in/?token=hidden';
			$_POST = array( 'pwd' => 'hidden', 'captcha_token' => 'hidden', 'rememberme' => 'forever', 'fs_lms_login' => '1' );
			$rows = array();
			$repository = $this->createMock( AuthLogRepository::class );
			$repository->expects( self::exactly( 2 ) )->method( 'create' )->willReturnCallback(
				static function ( AuthLogInputDTO $row ) use ( &$rows ): int {
					$rows[] = $row->details;
					return count( $rows );
				}
			);
			$clock = $this->createStub( ClockInterface::class );
			$clock->method( 'now' )->willReturn( '2026-11-21 12:00:00' );
			$writer = new AuthLogWriter( $repository, $clock );
			$writer->record( 'test', AuthAction::Login, AuthResult::Success );
			$writer->record( 'test', AuthAction::Login, AuthResult::Success );
			self::assertSame( $rows[0]['request_id'], $rows[1]['request_id'] );
			self::assertSame( 1, $rows[0]['request_event'] );
			self::assertSame( 2, $rows[1]['request_event'] );
			self::assertSame( '/wp-login.php', $rows[0]['request_path'] );
			self::assertSame( '/sign-in/', $rows[0]['referer_path'] );
			self::assertTrue( $rows[0]['remember_me'] );
			self::assertStringNotContainsString( 'hidden', json_encode( $rows ) );
		} finally {
			$_SERVER = $server;
			$_POST = $post;
		}
	}
}
