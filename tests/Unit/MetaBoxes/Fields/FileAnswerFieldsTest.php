<?php

declare( strict_types=1 );

namespace Unit\MetaBoxes\Fields;

use Inc\Enums\Subject\TaskTemplate;
use Inc\MetaBoxes\Fields\CriteriaField;
use Inc\MetaBoxes\Fields\FileAttachmentsField;
use Inc\MetaBoxes\Templates\FileAnswerTaskTemplate;
use PHPUnit\Framework\TestCase;

/** Поля шаблона «Развёрнутый ответ» (Эпик 13, D16/D17). */
class FileAnswerFieldsTest extends TestCase {

	/* ── FileAttachmentsField ────────────────────────────────────────────── */

	public function test_materials_sanitize_keeps_positive_ints_only(): void {
		$field = new FileAttachmentsField();

		self::assertSame(
			array( 'attachment_ids' => array( 5, 12 ) ),
			$field->sanitize( array( 'attachment_ids' => array( '5', '0', 'abc', 12, -3 ) ) )
		);
	}

	public function test_materials_sanitize_non_array_yields_empty(): void {
		$field = new FileAttachmentsField();

		self::assertSame( array( 'attachment_ids' => array() ), $field->sanitize( 'garbage' ) );
	}

	/* ── CriteriaField (D17: point-sum, без весов) ───────────────────────── */

	public function test_criteria_sanitize_drops_empty_labels_and_clamps_points(): void {
		$field = new CriteriaField();

		$result = $field->sanitize( array( 'criteria' => array(
			array( 'label' => 'К1 — обоснование', 'max_points' => '2' ),
			array( 'label' => '', 'max_points' => 9 ),              // пустой label → выбрасывается
			array( 'label' => 'К2', 'max_points' => -3 ),           // ≤0 → 1.0
			'not-an-array',
		) ) );

		self::assertSame( array( 'criteria' => array(
			array( 'label' => 'К1 — обоснование', 'max_points' => 2.0 ),
			array( 'label' => 'К2', 'max_points' => 1.0 ),
		) ), $result );
	}

	public function test_criteria_sanitize_non_array_yields_empty(): void {
		$field = new CriteriaField();

		self::assertSame( array( 'criteria' => array() ), $field->sanitize( null ) );
	}

	/* ── Шаблон и enum ───────────────────────────────────────────────────── */

	public function test_template_exposes_expected_field_keys(): void {
		$tpl = new FileAnswerTaskTemplate();

		self::assertSame( 'file_answer_task', $tpl->get_id() );
		self::assertSame(
			array( 'task_condition', 'task_materials', 'solution_text', 'task_code', 'task_criteria' ),
			array_keys( $tpl->get_fields() )
		);
	}

	public function test_enum_resolves_file_answer_case(): void {
		self::assertSame( TaskTemplate::FileAnswer, TaskTemplate::fromDatabase( 'file_answer_task' ) );
		self::assertSame( FileAnswerTaskTemplate::class, TaskTemplate::FileAnswer->class() );
	}
}
