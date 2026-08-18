<?php

declare( strict_types=1 );

namespace Unit\Controllers\Subject;

use Inc\Controllers\Subject\SubjectController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * `SubjectController::isTaskNumberTaxonomyQuery()` — условие numeric-sort фильтра
 * `get_terms_orderby`. Регресс: `get_terms()`-запросы без явной таксономии в query
 * vars (напр. `term_exists('1')` без второго аргумента) отдают сюда пустой массив —
 * `reset()` на пустом массиве даёт `false`, и `str_contains(false, ...)` кидал
 * TypeError при `strict_types=1` (падало создание терминов таксономии предмета).
 */
class SubjectControllerTermSortingTest extends TestCase {

	private function call( array $args ): bool {
		$method = new ReflectionMethod( SubjectController::class, 'isTaskNumberTaxonomyQuery' );

		return $method->invoke( null, $args );
	}

	public function test_true_for_task_number_taxonomy(): void {
		self::assertTrue( $this->call( array( 'taxonomy' => array( 'inf_task_number' ) ) ) );
	}

	public function test_false_for_other_taxonomy(): void {
		self::assertFalse( $this->call( array( 'taxonomy' => array( 'category' ) ) ) );
	}

	public function test_false_for_missing_taxonomy_key(): void {
		self::assertFalse( $this->call( array() ) );
	}

	public function test_false_for_empty_taxonomy_array(): void {
		self::assertFalse( $this->call( array( 'taxonomy' => array() ) ) );
	}

	public function test_false_for_non_string_first_element(): void {
		self::assertFalse( $this->call( array( 'taxonomy' => array( null ) ) ) );
	}
}
