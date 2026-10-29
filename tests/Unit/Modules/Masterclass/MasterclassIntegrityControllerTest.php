<?php

declare( strict_types=1 );

namespace Unit\Modules\Masterclass;

use Inc\Modules\Masterclass\Controllers\MasterclassIntegrityController;
use Inc\Modules\Masterclass\Repositories\MasterclassRepository;
use Inc\Modules\Masterclass\Services\MasterclassAccessGuard;
use PHPUnit\Framework\TestCase;

/**
 * Ш4 `fs_lms_service_group_ids`: группы мастер-классов дописываются к служебным и при выключенном модуле.
 */
class MasterclassIntegrityControllerTest extends TestCase {

	private function controller( MasterclassRepository $repository ): MasterclassIntegrityController {
		return new MasterclassIntegrityController( $repository, $this->createStub( MasterclassAccessGuard::class ) );
	}

	public function test_masterclass_groups_are_appended_to_the_service_ones(): void {
		$repository = $this->createStub( MasterclassRepository::class );
		$repository->method( 'activeGroupIds' )->willReturn( array( 41, 52 ) );

		$result = ( $this->controller( $repository ) )->filterServiceGroupIds( array( 7 ) );

		self::assertSame( array( 7, 41, 52 ), $result );
	}

	public function test_it_reads_the_table_and_not_the_module_flag(): void {
		// Контроллеру конфиг не передаётся вовсе: включён модуль или нет, группа остаётся служебной.
		$constructor = ( new \ReflectionClass( MasterclassIntegrityController::class ) )->getConstructor();
		$types       = array_map( static fn ( \ReflectionParameter $p ): string => (string) $p->getType(), $constructor->getParameters() );

		self::assertSame( array( MasterclassRepository::class, MasterclassAccessGuard::class ), $types );
	}

	public function test_without_masterclass_groups_the_list_is_unchanged(): void {
		$repository = $this->createStub( MasterclassRepository::class );
		$repository->method( 'activeGroupIds' )->willReturn( array() );

		self::assertSame( array( 7, 9 ), ( $this->controller( $repository ) )->filterServiceGroupIds( array( 7, 9 ) ) );
	}

	public function test_a_non_array_value_from_another_subscriber_is_treated_as_empty(): void {
		$repository = $this->createStub( MasterclassRepository::class );
		$repository->method( 'activeGroupIds' )->willReturn( array( 41 ) );

		self::assertSame( array( 41 ), ( $this->controller( $repository ) )->filterServiceGroupIds( 'oops' ) );
		self::assertSame( array( 41 ), ( $this->controller( $repository ) )->filterServiceGroupIds( null ) );
	}

	public function test_a_database_failure_does_not_break_core_screens(): void {
		$repository = $this->createStub( MasterclassRepository::class );
		$repository->method( 'activeGroupIds' )->willThrowException( new \RuntimeException( 'Table doesn\'t exist' ) );

		$result = ( $this->controller( $repository ) )->filterServiceGroupIds( array( 7, 9 ) );

		self::assertSame( array( 7, 9 ), $result, 'список возвращается как пришёл, исключение наружу не уходит' );
	}
}
