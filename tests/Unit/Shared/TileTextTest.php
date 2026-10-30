<?php

declare( strict_types=1 );

namespace Unit\Shared;

use Inc\Shared\TileText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Текст плитки и формулы «Перетаскивания пропусков»: из разметки допустимы только `<sub>` и `<sup>`. */
class TileTextTest extends TestCase {

	/** @return array<string, array{0: string, 1: string}> */
	public static function cleanCases(): array {
		return array(
			'индекс и степень сохраняются'      => array( 'x<sub>1</sub> + 2<sup>n</sup>', 'x<sub>1</sub> + 2<sup>n</sup>' ),
			'атрибуты у sub/sup отбрасываются'  => array( '<sub onclick="evil()" style="x">1</sub>', '<sub>1</sub>' ),
			'регистр тега приводится'           => array( 'x<SUB>1</SUB>', 'x<sub>1</sub>' ),
			'чужие теги вырезаются'             => array( '<b>x</b><script>alert(1)</script>', 'xalert(1)' ),
			'знак меньше без тега не страдает'  => array( 'a < b и a<b', 'a < b и a<b' ),
			'лишний закрывающий тег убирается'  => array( 'x</sub>1', 'x1' ),
			'незакрытый тег закрывается'        => array( 'x<sub>1', 'x<sub>1</sub>' ),
			'пробелы по краям срезаются'        => array( '  h  ', 'h' ),
			'пусто остаётся пустым'             => array( '', '' ),
		);
	}

	#[DataProvider( 'cleanCases' )]
	public function test_clean( string $raw, string $expected ): void {
		self::assertSame( $expected, TileText::clean( $raw ) );
	}

	public function test_html_escapes_everything_except_sub_and_sup(): void {
		self::assertSame( 'x<sub>1</sub> &lt; &amp; &quot;y&quot;', TileText::html( 'x<sub>1</sub> < & "y"' ) );
	}

	public function test_html_never_outputs_foreign_tags_even_from_unclean_storage(): void {
		self::assertSame( '&lt;img src=x onerror=1&gt;', TileText::html( '<img src=x onerror=1>' ) );
	}

	public function test_plain_drops_markup_for_readable_answers(): void {
		self::assertSame( 'x1 + 2n', TileText::plain( 'x<sub>1</sub> + 2<sup>n</sup>' ) );
	}
}
