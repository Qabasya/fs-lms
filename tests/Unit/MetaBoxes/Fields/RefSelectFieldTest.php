<?php

declare( strict_types=1 );

namespace Unit\MetaBoxes\Fields;

use Inc\MetaBoxes\Fields\TaskRefField;
use Inc\MetaBoxes\Fields\WorkTypeField;
use PHPUnit\Framework\TestCase;

class RefSelectFieldTest extends TestCase {

	public function test_ref_sanitize_returns_clean_int_list_in_order(): void {
		$field = new TaskRefField();

		self::assertSame( array( 3, 5, 9 ), $field->sanitize( array( '3', '', '5', 0, '9' ) ) );
	}

	public function test_ref_sanitize_non_array_is_empty(): void {
		$field = new TaskRefField();

		self::assertSame( array(), $field->sanitize( 'nonsense' ) );
		self::assertSame( array(), $field->sanitize( null ) );
	}

	public function test_work_type_sanitize_keeps_valid_and_defaults_invalid(): void {
		$field = new WorkTypeField();

		self::assertSame( 'homework', $field->sanitize( 'homework' ) );
		self::assertSame( 'practice', $field->sanitize( 'garbage' ) );
		self::assertSame( 'practice', $field->sanitize( null ) );
	}
}
