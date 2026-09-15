<?php

declare( strict_types=1 );

namespace Unit\Modules\ArticleBlocks;

use Inc\Modules\ArticleBlocks\Services\TableTextParser;
use PHPUnit\Framework\TestCase;

/**
 * Разбор поля «Таблица»: диапазон из электронной таблицы или строки через «|».
 */
class TableTextParserTest extends TestCase {

	public function test_parses_range_copied_from_spreadsheet(): void {
		$text = "x\ty\tF\n0\t1\t\n1\t0\t1\n";

		$this->assertSame(
			array(
				array( 'x', 'y', 'F' ),
				array( '0', '1', '' ),
				array( '1', '0', '1' ),
			),
			( new TableTextParser() )->parse( $text )
		);
	}

	public function test_parses_pipe_rows_and_skips_markdown_separator(): void {
		$text = "| Операция | Пример |\n|---|:---:|\n| Сумма | `a + b` |";

		$this->assertSame(
			array(
				array( 'Операция', 'Пример' ),
				array( 'Сумма', '`a + b`' ),
			),
			( new TableTextParser() )->parse( $text )
		);
	}

	public function test_tab_wins_over_pipe_inside_cells(): void {
		$this->assertSame(
			array( array( 'a|b', 'c' ) ),
			( new TableTextParser() )->parse( "a|b\tc" )
		);
	}

	public function test_pads_short_rows_and_skips_empty_lines(): void {
		$this->assertSame(
			array(
				array( 'a', 'b', 'c' ),
				array( 'd', '', '' ),
			),
			( new TableTextParser() )->parse( "\n a | b | c \n\n d \n\n" )
		);
	}

	public function test_empty_text_gives_no_rows(): void {
		$this->assertSame( array(), ( new TableTextParser() )->parse( " \n\t\n" ) );
	}
}
