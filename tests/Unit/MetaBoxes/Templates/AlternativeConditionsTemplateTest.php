<?php

declare( strict_types=1 );

namespace Unit\MetaBoxes\Templates;

use Inc\Enums\Subject\TaskTemplate;
use Inc\MetaBoxes\Templates\AlternativeConditionsTemplate;
use PHPUnit\Framework\TestCase;

/**
 * «Два условия на выбор» (ОГЭ №13, 2026-08-18) — один пост с двумя условиями
 * вместо двух постов 13.1/13.2 с дробными номерами (см. докблок класса и
 * OgeCriteriaConfig): нужен для целочисленного номера в таксономии
 * `{key}_task_number`, чтобы EgeCompletenessChecker мог дать строгую биекцию.
 */
class AlternativeConditionsTemplateTest extends TestCase {

	public function test_id_and_name(): void {
		$template = new AlternativeConditionsTemplate();

		self::assertSame( 'alternative_conditions_task', $template->get_id() );
		self::assertSame( 'Два условия на выбор (ОГЭ №13)', $template->get_name() );
	}

	public function test_has_two_condition_fields(): void {
		$template = new AlternativeConditionsTemplate();
		$fields   = $template->get_fields();

		self::assertArrayHasKey( 'task_condition_1', $fields );
		self::assertArrayHasKey( 'task_condition_2', $fields );
		self::assertNotSame(
			$fields['task_condition_1']['object'],
			$fields['task_condition_2']['object'],
			'два условия должны быть независимыми полями, не одним и тем же объектом'
		);
	}

	public function test_has_manual_review_apparatus_like_file_answer(): void {
		$fields = ( new AlternativeConditionsTemplate() )->get_fields();

		self::assertArrayHasKey( 'task_materials', $fields );
		self::assertArrayHasKey( 'solution_text', $fields );
		self::assertArrayHasKey( 'task_code', $fields );
		self::assertArrayHasKey( 'task_criteria', $fields );
		self::assertTrue( $fields['solution_text']['optional'] ?? false );
		self::assertTrue( $fields['task_code']['optional'] ?? false );
	}

	public function test_no_answer_field(): void {
		// Автопроверки нет намеренно (как у FileAnswer) — не должно быть task_answer.
		self::assertArrayNotHasKey( 'task_answer', ( new AlternativeConditionsTemplate() )->get_fields() );
	}

	public function test_registered_in_task_template_enum(): void {
		self::assertSame( AlternativeConditionsTemplate::class, TaskTemplate::AlternativeConditions->class() );
		self::assertSame(
			'alternative_conditions_task',
			TaskTemplate::AlternativeConditions->value
		);
		self::assertInstanceOf( AlternativeConditionsTemplate::class, new ( TaskTemplate::AlternativeConditions->class() )() );
	}

	public function test_from_database_round_trips(): void {
		self::assertSame(
			TaskTemplate::AlternativeConditions,
			TaskTemplate::fromDatabase( 'alternative_conditions_task' )
		);
	}

	/**
	 * Регресс (2026-08-18): станция ОГЭ (`kege/exam.php`) и générique-флоу
	 * попытки (`attempt-question.php`) решали «файл или текстовое поле» ТОЛЬКО
	 * по `=== TaskTemplate::FileAnswer`, пропуская новый тип — задание 13
	 * показывало бы текстовое поле вместо загрузки файла.
	 */
	public function test_is_file_answer_shape_true_for_alternative_conditions(): void {
		self::assertTrue( TaskTemplate::AlternativeConditions->isFileAnswerShape() );
		self::assertTrue( TaskTemplate::FileAnswer->isFileAnswerShape() );
	}

	public function test_is_file_answer_shape_false_for_other_templates(): void {
		self::assertFalse( TaskTemplate::Standard->isFileAnswerShape() );
		self::assertFalse( TaskTemplate::Triple->isFileAnswerShape() );
		self::assertFalse( TaskTemplate::Choice->isFileAnswerShape() );
		self::assertFalse( TaskTemplate::Code->isFileAnswerShape() );
	}
}
