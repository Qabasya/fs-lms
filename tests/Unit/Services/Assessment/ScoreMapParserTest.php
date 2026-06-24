<?php

declare( strict_types=1 );

namespace Unit\Services\Assessment;

use Inc\Services\Assessment\ScoreMapParser;
use PHPUnit\Framework\TestCase;

class ScoreMapParserTest extends TestCase {

	private ScoreMapParser $parser;

	protected function setUp(): void {
		$this->parser = new ScoreMapParser();
	}

	public function test_tab_separated(): void {
		$text = "0\t0\n5\t36\n10\t64";
		self::assertSame( [ 0 => 0, 5 => 36, 10 => 64 ], $this->parser->parse( $text ) );
	}

	public function test_semicolon_separated(): void {
		$text = "0;0\n5;36";
		self::assertSame( [ 0 => 0, 5 => 36 ], $this->parser->parse( $text ) );
	}

	public function test_comma_separated(): void {
		$text = "0,0\n5,36";
		self::assertSame( [ 0 => 0, 5 => 36 ], $this->parser->parse( $text ) );
	}

	public function test_multiple_spaces_separated(): void {
		$text = "0  0\n5  36";
		self::assertSame( [ 0 => 0, 5 => 36 ], $this->parser->parse( $text ) );
	}

	public function test_skips_non_numeric_header_rows(): void {
		$text = "Первичный\tВторичный\n0\t0\n5\t36";
		self::assertSame( [ 0 => 0, 5 => 36 ], $this->parser->parse( $text ) );
	}

	public function test_result_is_sorted_by_primary(): void {
		$text = "10\t64\n0\t0\n5\t36";
		$map  = $this->parser->parse( $text );
		self::assertSame( [ 0, 5, 10 ], array_keys( $map ) );
	}

	public function test_empty_input_returns_empty_array(): void {
		self::assertSame( [], $this->parser->parse( '' ) );
	}

	public function test_windows_line_endings(): void {
		$text = "0\t0\r\n5\t36\r\n";
		self::assertSame( [ 0 => 0, 5 => 36 ], $this->parser->parse( $text ) );
	}
}
