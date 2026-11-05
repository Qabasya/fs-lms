<?php

declare( strict_types=1 );

namespace Unit\Controllers\Course;

use Inc\Controllers\Course\SelfPacedContentGuardController;
use Inc\Managers\Wp\TermManager;
use Inc\Services\Course\SelfPacedContentGuard;
use Inc\Services\Task\TaskPublishGuard;
use PHPUnit\Framework\TestCase;

class SelfPacedContentGuardControllerTest extends TestCase {
	public function test_native_candidate_is_refused_before_save_without_throwing_to_wordpress(): void {
		$terms = $this->createMock( TermManager::class );
		$terms->expects( self::once() )->method( 'assertPostTerms' )->with( 20, array( '13' ), 'inf_task_number' )->willThrowException( new \InvalidArgumentException( 'Ручная проверка' ) );
		$notices = $this->createMock( TaskPublishGuard::class );
		$notices->expects( self::once() )->method( 'warn' );
		$controller = new SelfPacedContentGuardController( $this->createMock( SelfPacedContentGuard::class ), $notices, $terms );
		self::assertTrue( $controller->guardNativeTerms( false, array( 'ID' => 20, 'tax_input' => array( 'inf_task_number' => array( '13' ) ) ) ) );
	}

	public function test_upstream_metadata_refusal_is_preserved_without_domain_calls(): void {
		$guard = $this->createMock( SelfPacedContentGuard::class );
		$guard->expects( self::never() )->method( 'assertMetadata' );
		$controller = new SelfPacedContentGuardController( $guard, $this->createMock( TaskPublishGuard::class ), $this->createMock( TermManager::class ) );
		self::assertFalse( $controller->guardMetadata( false, 20, 'meta', array() ) );
	}

	public function test_other_taxonomy_is_not_restricted(): void {
		$terms = $this->createMock( TermManager::class );
		$terms->expects( self::never() )->method( 'assertPostTerms' );
		$controller = new SelfPacedContentGuardController( $this->createMock( SelfPacedContentGuard::class ), $this->createMock( TaskPublishGuard::class ), $terms );
		self::assertFalse( $controller->guardNativeTerms( false, array( 'ID' => 20, 'tax_input' => array( 'category' => array( 13 ) ) ) ) );
	}
	public function test_rest_candidate_taxonomy_is_rejected_before_post_update(): void {
		$terms = $this->createMock( TermManager::class );
		$terms->method( 'restTaskNumberFields' )->with( 'inf_tasks' )->willReturn( array( 'numbers' => 'inf_task_number' ) );
		$terms->expects( self::once() )->method( 'assertPostTerms' )->with( 20, array( 13 ), 'inf_task_number' )->willThrowException( new \InvalidArgumentException( 'Ручная проверка запрещена' ) );
		$controller = new SelfPacedContentGuardController( $this->createMock( SelfPacedContentGuard::class ), $this->createMock( TaskPublishGuard::class ), $terms );
		$request = new class {
			public function get_param( string $field ): mixed { return 'id' === $field ? 20 : array( '13' ); }
			public function has_param( string $field ): bool { return 'numbers' === $field; }
		};
		$result = $controller->guardRestTerms( (object) array( 'post_type' => 'inf_tasks' ), $request );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'fs_lms_self_paced_content', $result->get_error_code() );
	}

}
