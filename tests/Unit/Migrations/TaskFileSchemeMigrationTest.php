<?php

declare( strict_types=1 );

namespace Unit\Migrations;

use Inc\Migrations\TaskFileSchemeMigration;
use Inc\Services\Task\TaskFileSchemeService;
use PHPUnit\Framework\TestCase;

/**
 * Миграция чинит ссылки сама — на хостинге без WP-CLI запустить команду нечем.
 * Гейт — собственная опция: на уже мигрированной установке это одно чтение.
 */
class TaskFileSchemeMigrationTest extends TestCase {

	private const OPTION = 'fs_lms_task_file_scheme_version';

	protected function setUp(): void {
		parent::setUp();
		delete_option( self::OPTION );
	}

	protected function tearDown(): void {
		delete_option( self::OPTION );
		parent::tearDown();
	}

	public function test_first_run_migrates_and_records_version(): void {
		$service = $this->createMock( TaskFileSchemeService::class );
		$service->expects( $this->once() )
			->method( 'migrate' )
			->willReturn( array( 'scanned' => 1, 'updated' => 1, 'links' => 1 ) );

		( new TaskFileSchemeMigration( $service ) )->run();

		self::assertSame( '1', get_option( self::OPTION ) );
	}

	public function test_second_run_does_nothing(): void {
		$service = $this->createMock( TaskFileSchemeService::class );
		$service->expects( $this->once() )
			->method( 'migrate' )
			->willReturn( array( 'scanned' => 0, 'updated' => 0, 'links' => 0 ) );

		$migration = new TaskFileSchemeMigration( $service );
		$migration->run();
		$migration->run();
	}

	public function test_version_is_recorded_even_when_nothing_to_fix(): void {
		// Иначе запрос к postmeta повторялся бы на каждой загрузке страницы.
		$service = $this->createStub( TaskFileSchemeService::class );
		$service->method( 'migrate' )->willReturn( array( 'scanned' => 0, 'updated' => 0, 'links' => 0 ) );

		( new TaskFileSchemeMigration( $service ) )->run();

		self::assertSame( '1', get_option( self::OPTION ) );
	}
}
