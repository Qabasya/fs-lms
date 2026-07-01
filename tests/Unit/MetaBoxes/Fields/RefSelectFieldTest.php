<?php

declare( strict_types=1 );

namespace Unit\MetaBoxes\Fields;

use Inc\MetaBoxes\Fields\WorkTypeField;
use PHPUnit\Framework\TestCase;

class RefSelectFieldTest extends TestCase {

	public function test_work_type_sanitize_keeps_valid_and_defaults_invalid(): void {
		$field = new WorkTypeField();

		self::assertSame( 'homework', $field->sanitize( 'homework' ) );
		self::assertSame( 'practice', $field->sanitize( 'garbage' ) );
		self::assertSame( 'practice', $field->sanitize( null ) );
	}
}
