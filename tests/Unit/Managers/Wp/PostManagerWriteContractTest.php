<?php

declare( strict_types=1 );

namespace Tests\Unit\Managers\Wp;

use Inc\Managers\Wp\PostManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Ошибки записи WordPress остаются нулём/false на границе менеджера. */
class PostManagerWriteContractTest extends TestCase {

	public static function updateMethods(): array {
		return array(
			'normal' => array( 'update' ),
			'without lifecycle' => array( 'updateBypassingHooks' ),
		);
	}

	#[DataProvider( 'updateMethods' )]
	public function test_missing_post_update_is_false( string $method ): void {
		unset( $GLOBALS['_fs_test_posts'][ -42 ] );
		self::assertFalse( ( new PostManager() )->$method( -42, array( 'post_title' => 'Missing post' ) ) );
	}

	public function test_missing_post_status_update_is_false(): void {
		unset( $GLOBALS['_fs_test_posts'][ -42 ] );
		self::assertFalse( ( new PostManager() )->updateStatus( -42, 'publish' ) );
	}

	public function test_wp_error_flag_changes_the_stub_error_contract(): void {
		unset( $GLOBALS['_fs_test_posts'][ -42 ] );
		self::assertSame( 0, wp_update_post( array( 'ID' => -42 ) ) );
		self::assertInstanceOf( \WP_Error::class, wp_update_post( array( 'ID' => -42 ), true ) );
	}
}
