<?php

declare( strict_types=1 );

namespace Unit\Modules\AdSync;

use Inc\Modules\AdSync\Config\AdSyncConfig;
use PHPUnit\Framework\TestCase;

class AdSyncConfigTest extends TestCase {

	public function test_exclusions_are_explicit_and_case_insensitive(): void {
		$before = $GLOBALS['_test_options'] ?? array();
		try {
			$config = new AdSyncConfig();
			$config->save( array( 'excluded_usernames' => array( ' test ', 'TEST', '', 'Demo' ) ) );
			self::assertSame( array( 'test', 'demo' ), $config->excludedUsernames() );
			self::assertTrue( $config->isExcluded( 'Test' ) );
			self::assertFalse( $config->isExcluded( 'test-ege' ) );
			$config->save( array( 'reconcile_apply' => false ) );
			self::assertTrue( $config->isExcluded( 'test' ) );
		} finally {
			$GLOBALS['_test_options'] = $before;
		}
	}
}
