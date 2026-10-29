<?php

declare( strict_types=1 );

namespace Unit\MetaBoxes\Fields;

use Inc\MetaBoxes\Fields\GapTextField;
use PHPUnit\Framework\TestCase;

class GapTextFieldTest extends TestCase {

	private GapTextField $field;

	protected function setUp(): void {
		$this->field = new GapTextField();
	}

	public function test_tiles_mode_and_extra_tiles_are_kept(): void {
		$result = $this->field->sanitize( array(
			'text'        => 'Это [[кот]]',
			'answer_mode' => 'tiles',
			'extra_tiles' => array( 'пёс', ' ёж ', '' ),
		) );

		self::assertSame( 'tiles', $result['answer_mode'] );
		self::assertSame( array( 'пёс', 'ёж' ), $result['extra_tiles'] );
	}

	public function test_unknown_mode_falls_back_to_input(): void {
		$result = $this->field->sanitize( array( 'text' => 'x', 'answer_mode' => 'drag' ) );

		self::assertSame( 'input', $result['answer_mode'] );
		self::assertSame( array(), $result['extra_tiles'] );
	}

	public function test_extra_tiles_from_textarea_are_split_by_lines(): void {
		$result = $this->field->sanitize( array( 'text' => 'x', 'extra_tiles' => "пёс\r\n\n ёж \n" ) );

		self::assertSame( array( 'пёс', 'ёж' ), $result['extra_tiles'] );
	}

	public function test_garbage_extra_tiles_are_ignored(): void {
		$result = $this->field->sanitize( array( 'text' => 'x', 'extra_tiles' => 5 ) );

		self::assertSame( array(), $result['extra_tiles'] );
	}

	public function test_non_array_value_gives_empty_input_field(): void {
		self::assertSame(
			array( 'text' => '', 'answer_mode' => 'input', 'extra_tiles' => array() ),
			$this->field->sanitize( 'мусор' )
		);
	}
}
