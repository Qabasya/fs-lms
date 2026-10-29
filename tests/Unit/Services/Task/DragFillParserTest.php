<?php

declare( strict_types=1 );

namespace Unit\Services\Task;

use Inc\Services\Task\DragFillParser;
use PHPUnit\Framework\TestCase;

class DragFillParserTest extends TestCase {

	public function test_formula_splits_into_text_and_slots(): void {
		self::assertSame(
			array(
				array( 'type' => 'text', 'content' => 'V = ' ),
				array( 'type' => 'slot', 'index' => 0 ),
				array( 'type' => 'text', 'content' => ' * ' ),
				array( 'type' => 'slot', 'index' => 1 ),
			),
			DragFillParser::segments( 'V = {1} * {2}' )
		);
	}

	public function test_slot_count_is_highest_number(): void {
		self::assertSame( 3, DragFillParser::slotCount( 'V = {1} * {2} * {3}' ) );
		self::assertSame( 0, DragFillParser::slotCount( 'без слотов' ) );
	}

	public function test_slot_numbers_map_to_zero_based_indexes_in_any_order(): void {
		$slots = array_values( array_filter(
			DragFillParser::segments( '{2} + {1}' ),
			static fn( array $s ): bool => 'slot' === $s['type']
		) );

		self::assertSame( array( 1, 0 ), array_column( $slots, 'index' ) );
	}

	public function test_render_substitutes_answers_and_marks_empty_slots(): void {
		self::assertSame( 'V = h * w * i', DragFillParser::render( 'V = {1} * {2} * {3}', array( 'h', 'w', 'i' ) ) );
		self::assertSame( 'V = h * … * …', DragFillParser::render( 'V = {1} * {2} * {3}', array( 'h' ) ) );
	}
}
