<?php

declare( strict_types=1 );

namespace Unit\Modules\Masterclass;

use Inc\Modules\Masterclass\Config\MasterclassConfig;
use Inc\Modules\Masterclass\Controllers\MasterclassAdminController;
use Inc\Modules\Masterclass\Controllers\MasterclassAuthController;
use Inc\Modules\Masterclass\Controllers\MasterclassCronController;
use Inc\Modules\Masterclass\Controllers\MasterclassIntegrityController;
use Inc\Modules\Masterclass\Controllers\MasterclassPlayerController;
use Inc\Modules\Masterclass\Controllers\MasterclassSettingsController;
use Inc\Modules\Masterclass\MasterclassModule;
use Inc\Modules\Masterclass\Schema\MasterclassSchema;
use PHPUnit\Framework\TestCase;

/**
 * Каркас модуля: что регистрируется при выключенном и включённом модуле и в каком порядке.
 */
class MasterclassModuleTest extends TestCase {

	/** @var list<string> */
	private array $calls = array();

	protected function setUp(): void {
		parent::setUp();
		$this->calls = array();
	}

	private function module( bool $enabled ): MasterclassModule {
		$schema = $this->createStub( MasterclassSchema::class );
		$schema->method( 'ensure' )->willReturnCallback( fn() => $this->calls[] = 'schema' );

		$config = $this->createStub( MasterclassConfig::class );
		$config->method( 'isEnabled' )->willReturn( $enabled );

		$settings = $this->createStub( MasterclassSettingsController::class );
		$settings->method( 'register' )->willReturnCallback( fn() => $this->calls[] = 'settings' );

		$integrity = $this->createStub( MasterclassIntegrityController::class );
		$integrity->method( 'register' )->willReturnCallback( fn() => $this->calls[] = 'integrity' );

		$admin = $this->createStub( MasterclassAdminController::class );
		$admin->method( 'register' )->willReturnCallback( fn() => $this->calls[] = 'admin' );

		$auth = $this->createStub( MasterclassAuthController::class );
		$auth->method( 'register' )->willReturnCallback( fn() => $this->calls[] = 'auth' );

		$player = $this->createStub( MasterclassPlayerController::class );
		$player->method( 'register' )->willReturnCallback( fn() => $this->calls[] = 'player' );

		$cron = $this->createStub( MasterclassCronController::class );
		$cron->method( 'register' )->willReturnCallback( fn() => $this->calls[] = 'cron' );

		return new MasterclassModule( $schema, $config, $settings, $integrity, $admin, $auth, $player, $cron );
	}

	public function test_disabled_module_registers_schema_settings_and_integrity_only(): void {
		$this->module( false )->register();

		self::assertSame( array( 'schema', 'settings', 'integrity' ), $this->calls );
	}

	public function test_enabled_module_registers_everything_after_them(): void {
		$this->module( true )->register();

		self::assertSame( array( 'schema', 'settings', 'integrity', 'admin', 'auth', 'player', 'cron' ), $this->calls );
	}

	public function test_the_schema_is_ensured_before_anything_that_reads_tables(): void {
		foreach ( array( false, true ) as $enabled ) {
			$this->calls = array();
			$this->module( $enabled )->register();

			self::assertSame( 'schema', $this->calls[0], 'таблицы создаются первыми — и при выключенном модуле' );
		}
	}

	public function test_the_module_is_the_only_service_the_core_knows(): void {
		self::assertTrue( ( new \ReflectionClass( MasterclassModule::class ) )->implementsInterface( \Inc\Contracts\ServiceInterface::class ) );
	}
}
