<?php

declare( strict_types=1 );

namespace Tests\Unit\Migrations;

use Inc\Enums\Settings\OptionName;
use Inc\Enums\Wp\PageRoutes;
use Inc\Migrations\ExamPagesMigration;
use Inc\Repositories\OptionsRepositories\ExamPagesVersionRepository;
use Inc\Services\System\PageGeneratorService;
use PHPUnit\Framework\TestCase;

class ExamPagesMigrationTest extends TestCase {

	protected function tearDown(): void {
		delete_option( OptionName::ExamPagesVersion->value );
		parent::tearDown();
	}

	public function test_marker_is_saved_only_after_all_four_pages_and_skips_repeat(): void {
		delete_option( OptionName::ExamPagesVersion->value );
		$pages = $this->createMock( PageGeneratorService::class );
		$routes = array();
		$pages->expects( self::exactly( 4 ) )->method( 'ensurePublished' )->willReturnCallback( static function ( PageRoutes $route ) use ( &$routes ): void {
			self::assertNotSame( '3', get_option( OptionName::ExamPagesVersion->value ) );
			$routes[] = $route;
		} );
		$migration = new ExamPagesMigration( $pages, new ExamPagesVersionRepository() );
		$migration->run();
		$migration->run();
		self::assertSame( array( PageRoutes::ExamSignup, PageRoutes::ExamEntry, PageRoutes::ExamResult, PageRoutes::ExamReport ), $routes );
		self::assertSame( '3', get_option( OptionName::ExamPagesVersion->value ) );
	}

	public function test_failure_keeps_previous_marker_and_next_run_retries(): void {
		update_option( OptionName::ExamPagesVersion->value, '2' );
		$pages = $this->createMock( PageGeneratorService::class );
		$calls = 0;
		$pages->expects( self::exactly( 6 ) )->method( 'ensurePublished' )->willReturnCallback( static function () use ( &$calls ): void {
			if ( 2 === ++$calls ) {
				throw new \RuntimeException( 'stand: insert failed' );
			}
		} );
		$migration = new ExamPagesMigration( $pages, new ExamPagesVersionRepository() );
		$migration->run();
		self::assertSame( '2', get_option( OptionName::ExamPagesVersion->value ) );
		$migration->run();
		self::assertSame( '3', get_option( OptionName::ExamPagesVersion->value ) );
	}
	public function test_marker_write_failure_allows_retry(): void {
		$pages = $this->createMock( PageGeneratorService::class );
		$pages->expects( self::exactly( 8 ) )->method( 'ensurePublished' );
		$versions = $this->createMock( ExamPagesVersionRepository::class );
		$versions->method( 'get' )->willReturn( '2' );
		$versions->expects( self::exactly( 2 ) )->method( 'save' )->with( '3' )->willReturnOnConsecutiveCalls( false, true );
		$migration = new ExamPagesMigration( $pages, $versions );
		$migration->run();
		$migration->run();
	}
}
