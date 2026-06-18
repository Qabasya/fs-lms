<?php

declare( strict_types=1 );

namespace Unit\DTO\Course;

use Inc\DTO\Course\ModuleDTO;
use PHPUnit\Framework\TestCase;

class ModuleDTOTest extends TestCase {

	public function test_round_trip_keeps_id_title_lessons(): void {
		$dto     = new ModuleDTO( 'm1', 'Задание 7', array( 10, 11, 12 ) );
		$rebuilt = ModuleDTO::fromArray( $dto->toArray() );

		self::assertSame( 'm1', $rebuilt->id );
		self::assertSame( 'Задание 7', $rebuilt->title );
		self::assertSame( array( 10, 11, 12 ), $rebuilt->lessonIds );
	}

	public function test_to_array_uses_snake_case_keys(): void {
		$dto = new ModuleDTO( 'm1', 'M', array( 1 ) );
		self::assertArrayHasKey( 'lesson_ids', $dto->toArray() );
	}

	public function test_from_array_intval_filters_lesson_ids(): void {
		$dto = ModuleDTO::fromArray(
			array( 'id' => 'm', 'title' => 'T', 'lesson_ids' => array( '5', 0, '7', null, 'x' ) )
		);
		self::assertSame( array( 5, 7 ), $dto->lessonIds );
	}

	public function test_from_array_defaults_on_missing_fields(): void {
		$dto = ModuleDTO::fromArray( array() );
		self::assertSame( '', $dto->id );
		self::assertSame( '', $dto->title );
		self::assertSame( array(), $dto->lessonIds );
		self::assertTrue( $dto->isEmpty() );
	}

	public function test_from_list_and_to_list_round_trip(): void {
		$rows = array(
			array( 'id' => 'm1', 'title' => 'Модуль 1', 'lesson_ids' => array( 1, 2 ) ),
			'garbage',
			array( 'id' => 'm2', 'title' => 'Модуль 2', 'lesson_ids' => array( 3 ) ),
		);

		$modules = ModuleDTO::fromList( $rows );
		self::assertCount( 2, $modules );
		self::assertSame( 'm2', $modules[1]->id );

		$serialized = ModuleDTO::toList( $modules );
		self::assertSame( array( 1, 2 ), $serialized[0]['lesson_ids'] );
		self::assertSame( 'Модуль 2', $serialized[1]['title'] );
	}
}
