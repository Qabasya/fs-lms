<?php

declare( strict_types=1 );

namespace Unit\Templates;

use PHPUnit\Framework\TestCase;

/** «Показать решение»: у «Перетаскивания пропусков» — итоговая формула в том же виде, что и условие, а не строка ответа. */
class TeacherSolutionTemplateTest extends TestCase {

	/** @param array<string, mixed> $solution */
	private function render( array $solution ): string {
		$teacher_solution = $solution;
		ob_start();
		include __DIR__ . '/../../../templates/frontend/lesson-player/partials/teacher-solution.php';
		return (string) ob_get_clean();
	}

	private function dragFillSolution(): array {
		return array(
			'answer'   => 'S = x1 + y',
			'html'     => '',
			'code'     => '',
			'variants' => array( 'drag_fill' => array(
				array( 'type' => 'text', 'html' => 'S = ' ),
				array( 'type' => 'slot', 'html' => 'x<sub>1</sub>' ),
				array( 'type' => 'text', 'html' => ' + ' ),
				array( 'type' => 'slot', 'html' => 'y' ),
			) ),
		);
	}

	public function test_drag_fill_solution_is_a_formula_block_with_substituted_values(): void {
		$html = $this->render( $this->dragFillSolution() );

		self::assertStringContainsString( 'fs-drag-fill-formula fs-drag-fill-formula--solution', $html );
		self::assertStringContainsString( 'x<sub>1</sub>', $html );
		self::assertSame( 2, substr_count( $html, 'fs-drag-fill-answer' ) );
	}

	public function test_drag_fill_solution_has_no_separate_answer_row_and_no_tile_bank(): void {
		$html = $this->render( $this->dragFillSolution() );

		self::assertStringNotContainsString( 'Правильный ответ', $html );
		self::assertStringNotContainsString( 'fs-tile-bank', $html );
	}

	public function test_other_templates_keep_the_answer_row(): void {
		$html = $this->render( array( 'answer' => '42', 'html' => '', 'code' => '', 'variants' => array( 'drag_fill' => array() ) ) );

		self::assertStringContainsString( 'Правильный ответ', $html );
		self::assertStringNotContainsString( 'fs-drag-fill-formula', $html );
	}
}
