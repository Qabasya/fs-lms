<?php

declare(strict_types=1);

namespace Unit\MetaBoxes\Fields;

use Inc\MetaBoxes\Fields\AnswerInputField;
use Inc\MetaBoxes\Fields\TextareaField;
use PHPUnit\Framework\TestCase;

class TextareaFieldTest extends TestCase {
	public function test_answer_preserves_comparison_signs_and_backslashes(): void {
		$field = new TextareaField();

		self::assertSame( '<', $field->sanitize( '<' ) );
		self::assertSame( '2<3 и 5>4', $field->sanitize( '2<3 и 5>4' ) );
		self::assertSame( 'C:\\temp\\d+', $field->sanitize( 'C:\\temp\\d+' ) );
	}

	public function test_single_line_composite_answer_preserves_less_than_sign(): void {
		self::assertSame( '<', ( new AnswerInputField() )->sanitize( '<' ) );
	}
}
