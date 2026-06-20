<?php

declare( strict_types=1 );

namespace Unit\Services\Application;

use Inc\Repositories\OptionsRepositories\PluginConfigRepository;
use Inc\Services\Application\ApplicationSettingsService;
use PHPUnit\Framework\TestCase;

class ApplicationSettingsServiceTest extends TestCase {

	private function make( array $config ): ApplicationSettingsService {
		$repo = $this->createMock( PluginConfigRepository::class );
		$repo->method( 'get' )->willReturn( $config );
		return new ApplicationSettingsService( $repo );
	}

	public function test_is_bind_to_subject_reads_flag(): void {
		self::assertTrue( $this->make( array( 'applications_bind_to_subject' => true ) )->isBindToSubject() );
		self::assertFalse( $this->make( array( 'applications_bind_to_subject' => false ) )->isBindToSubject() );
		self::assertFalse( $this->make( array() )->isBindToSubject() );
	}

	public function test_direction_codes_returns_map_or_empty(): void {
		$svc = $this->make( array( 'direction_codes' => array( 'inf' => '111', 'math' => '222' ) ) );
		self::assertSame( array( 'inf' => '111', 'math' => '222' ), $svc->directionCodes() );

		self::assertSame( array(), $this->make( array() )->directionCodes() );
		self::assertSame( array(), $this->make( array( 'direction_codes' => 'broken' ) )->directionCodes() );
	}

	public function test_resolve_subject_by_code_matches(): void {
		$svc = $this->make( array( 'direction_codes' => array( 'inf' => '111', 'math' => '222' ) ) );
		self::assertSame( 'inf', $svc->resolveSubjectByCode( '111' ) );
		self::assertSame( 'math', $svc->resolveSubjectByCode( '222' ) );
	}

	public function test_resolve_subject_by_code_trims_input(): void {
		$svc = $this->make( array( 'direction_codes' => array( 'inf' => '111' ) ) );
		self::assertSame( 'inf', $svc->resolveSubjectByCode( '  111 ' ) );
	}

	public function test_resolve_returns_null_for_unknown_or_empty(): void {
		$svc = $this->make( array( 'direction_codes' => array( 'inf' => '111' ) ) );
		self::assertNull( $svc->resolveSubjectByCode( '999' ) );
		self::assertNull( $svc->resolveSubjectByCode( '' ) );
		self::assertNull( $svc->resolveSubjectByCode( '   ' ) );
	}

	public function test_resolve_ignores_empty_configured_codes(): void {
		// Пустой код в конфиге не должен матчиться пустым/любым вводом.
		$svc = $this->make( array( 'direction_codes' => array( 'inf' => '', 'math' => '222' ) ) );
		self::assertNull( $svc->resolveSubjectByCode( '' ) );
		self::assertSame( 'math', $svc->resolveSubjectByCode( '222' ) );
	}
}
