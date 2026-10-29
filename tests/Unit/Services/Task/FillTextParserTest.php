<?php

declare( strict_types=1 );

namespace Unit\Services\Task;

use Inc\Services\Task\FillTextParser;
use PHPUnit\Framework\TestCase;

class FillTextParserTest extends TestCase {

	public function test_tiles_are_first_variants_of_each_gap(): void {
		$parsed = FillTextParser::parse( 'Цвет — [[красный|алый]], форма — [[круг]].' );

		self::assertSame( array( 'красный', 'круг' ), FillTextParser::tilesFor( $parsed, array() ) );
	}

	public function test_extra_tiles_are_appended_after_gap_tiles(): void {
		$parsed = FillTextParser::parse( 'Это [[кот]].' );

		self::assertSame( array( 'кот', 'пёс', 'ёж' ), FillTextParser::tilesFor( $parsed, array( 'пёс', 'ёж' ) ) );
	}

	public function test_empty_and_blank_tiles_are_dropped(): void {
		$parsed = FillTextParser::parse( 'Это [[ ]] и [[кот]].' );

		self::assertSame( array( 'кот', 'пёс' ), FillTextParser::tilesFor( $parsed, array( '', '  ', 'пёс' ) ) );
	}

	public function test_duplicate_answers_stay_as_separate_tiles(): void {
		$parsed = FillTextParser::parse( '[[да]] и [[да]]' );

		self::assertSame( array( 'да', 'да' ), FillTextParser::tilesFor( $parsed, array() ) );
	}
}
