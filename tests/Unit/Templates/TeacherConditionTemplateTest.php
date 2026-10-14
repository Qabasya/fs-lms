<?php

declare( strict_types=1 );

namespace Unit\Templates;

use PHPUnit\Framework\TestCase;

/**
 * Режим преподавателя: варианты выбора, сопоставления и сортировки рисуются как у ученика,
 * но без полей ввода; верные помечаются для подсветки под «Показать решение».
 */
class TeacherConditionTemplateTest extends TestCase {

	/**
	 * @param array<string, mixed> $task
	 */
	private function render( array $task ): string {
		$cond_task  = $task;
		$cond_class = 'fs-task-condition wpc';
		ob_start();
		include __DIR__ . '/../../../templates/frontend/lesson-player/partials/teacher-condition.php';
		return (string) ob_get_clean();
	}

	public function test_choice_marks_only_correct_options(): void {
		$html = $this->render( array(
			'template'    => 'choice_task',
			'widget_data' => array(
				'type'     => 'choice',
				'multiple' => true,
				'options'  => array(
					array( 'id' => '1', 'text' => 'Верный' ),
					array( 'id' => '2', 'text' => 'Неверный' ),
				),
			),
			'solution'    => array( 'variants' => array( 'correct_ids' => array( '1' ) ) ),
		) );

		self::assertSame( 2, substr_count( $html, 'class="opt dis"' ) );
		self::assertSame( 1, substr_count( $html, 'data-key' ) );
		self::assertStringContainsString( 'is-checkbox', $html );
		self::assertStringNotContainsString( '<input', $html );
	}

	public function test_matching_shows_correct_right_hidden_until_solution_opens(): void {
		$html = $this->render( array(
			'template'    => 'matching_task',
			'widget_data' => array(
				'type'   => 'matching',
				'lefts'  => array( array( 'id' => 0, 'text' => 'Кошка' ) ),
				'rights' => array( 'мяу', 'гав' ),
			),
			'solution'    => array( 'variants' => array( 'pairs' => array( array( 'left' => 'Кошка', 'right' => 'мяу' ) ) ) ),
		) );

		self::assertStringContainsString( 'fs-widget-matching-select', $html );
		self::assertStringContainsString( '<span class="fs-teacher-key">мяу</span>', $html );
	}

	public function test_ordering_renders_bank_and_correct_order(): void {
		$html = $this->render( array(
			'template'    => 'ordering_task',
			'widget_data' => array( 'type' => 'ordering', 'items' => array(
				array( 'id' => 0, 'text' => 'второй' ),
				array( 'id' => 1, 'text' => 'первый' ),
			) ),
			'solution'    => array( 'variants' => array( 'order' => array( 'первый', 'второй' ) ) ),
		) );

		self::assertStringContainsString( 'fs-teacher-variants--bank', $html );
		self::assertStringContainsString( 'fs-teacher-variants--key', $html );
		self::assertLessThan( strpos( $html, 'второй', strpos( $html, '--key' ) ), strpos( $html, 'первый', strpos( $html, '--key' ) ) );
	}
}
