<?php

declare( strict_types=1 );

namespace Unit\MetaBoxes\Fields;

use Inc\MetaBoxes\Fields\DragFillField;
use PHPUnit\Framework\TestCase;

class DragFillFieldTest extends TestCase {

	private DragFillField $field;

	protected function setUp(): void {
		$this->field = new DragFillField();
	}

	public function test_valid_value_is_kept(): void {
		$value = array(
			'formula' => 'V = {1} * {2}',
			'tiles'   => array( 'h', 'w', 'k' ),
			'slots'   => array( array( 'answer' => 'h', 'group' => 1 ), array( 'answer' => 'w', 'group' => 1 ) ),
		);

		self::assertSame( $value, $this->field->sanitize( $value ) );
	}

	public function test_slots_are_padded_or_trimmed_to_formula(): void {
		$short = $this->field->sanitize( array(
			'formula' => '{1} + {2} + {3}',
			'tiles'   => array( 'a' ),
			'slots'   => array( array( 'answer' => 'a', 'group' => 1 ) ),
		) );
		self::assertCount( 3, $short['slots'] );
		self::assertSame( '', $short['slots'][2]['answer'] );

		$long = $this->field->sanitize( array(
			'formula' => '{1}',
			'tiles'   => array( 'a', 'b' ),
			'slots'   => array( array( 'answer' => 'a', 'group' => 1 ), array( 'answer' => 'b', 'group' => 1 ) ),
		) );
		self::assertCount( 1, $long['slots'] );
	}

	public function test_groups_are_positive_integers_and_missing_get_their_own(): void {
		$result = $this->field->sanitize( array(
			'formula' => '{1} {2} {3}',
			'tiles'   => array( 'a', 'b', 'c' ),
			'slots'   => array(
				array( 'answer' => 'a', 'group' => '2' ),
				array( 'answer' => 'b', 'group' => 0 ),
				array( 'answer' => 'c' ),
			),
		) );

		$groups = array_column( $result['slots'], 'group' );
		self::assertSame( 2, $groups[0] );
		self::assertSame( 3, count( array_unique( $groups ) ) );
		foreach ( $groups as $group ) {
			self::assertIsInt( $group );
			self::assertGreaterThanOrEqual( 1, $group );
		}
	}

	public function test_blank_tiles_are_dropped_and_answers_are_added_to_tiles(): void {
		$result = $this->field->sanitize( array(
			'formula' => '{1} {2}',
			'tiles'   => array( ' a ', '', '   ' ),
			'slots'   => array( array( 'answer' => 'a', 'group' => 1 ), array( 'answer' => 'b', 'group' => 2 ) ),
		) );

		self::assertSame( array( 'a', 'b' ), $result['tiles'] );
	}

	public function test_repeated_answers_need_repeated_tiles(): void {
		$result = $this->field->sanitize( array(
			'formula' => '{1} {2}',
			'tiles'   => array( 'h' ),
			'slots'   => array( array( 'answer' => 'h', 'group' => 1 ), array( 'answer' => 'h', 'group' => 1 ) ),
		) );

		self::assertSame( array( 'h', 'h' ), $result['tiles'] );
	}

	public function test_garbage_gives_empty_value(): void {
		self::assertSame( array( 'formula' => '', 'tiles' => array(), 'slots' => array() ), $this->field->sanitize( 'мусор' ) );
	}

	public function test_editor_type(): void {
		self::assertSame( 'drag_fill', $this->field->editorType() );
	}
}
