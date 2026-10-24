<?php

declare( strict_types=1 );

namespace Unit\Managers\Person;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Регрессия: в WP-Cron wp_delete_user() не загружена (она в wp-admin/includes/user.php),
 * поэтому удаление учётки из крона падало. Оба места вызова обязаны подключать файл сами.
 */
class UserDeleteCronLoadTest extends TestCase {

	/** @return array<string, array{string}> */
	public static function callSites(): array {
		return array(
			'UserManager'    => array( 'inc/Managers/Person/UserManager.php' ),
			'UserRepository' => array( 'inc/Repositories/OptionsRepositories/UserRepository.php' ),
		);
	}

	#[DataProvider( 'callSites' )]
	public function test_wp_delete_user_is_preceded_by_include( string $file ): void {
		$src = (string) file_get_contents( dirname( __DIR__, 4 ) . '/' . $file );

		$call    = strpos( $src, 'return wp_delete_user(' ) ?: strpos( $src, "\n\t\twp_delete_user(" );
		$include = strpos( $src, "require_once ABSPATH . 'wp-admin/includes/user.php'" );

		$this->assertNotFalse( $call );
		$this->assertNotFalse( $include );
		$this->assertLessThan( $call, $include );
	}
}
