<?php

declare( strict_types=1 );

namespace Unit\Services\Task;

use Inc\Services\Task\ConditionHtmlNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Условия из Word/легаси-импорта приезжали с пустыми строками в конце —
 * в курсе под условием висели пустые абзацы.
 */
class ConditionHtmlNormalizerTest extends TestCase {

	private ConditionHtmlNormalizer $normalizer;

	protected function setUp(): void {
		parent::setUp();
		$this->normalizer = new ConditionHtmlNormalizer();
	}

	public function test_trailing_empty_paragraphs_are_removed(): void {
		self::assertSame(
			'<p>Найдите x.</p>',
			$this->normalizer->normalize( "<p>Найдите x.</p>\n<p>&nbsp;</p>\n<p> </p><p><br></p>" )
		);
	}

	public function test_leading_empty_blocks_and_breaks_are_removed(): void {
		self::assertSame(
			'<p>Условие</p>',
			$this->normalizer->normalize( "<br />\n<p>&#160;</p><div>\xC2\xA0</div><p>Условие</p><br>" )
		);
	}

	public function test_raw_field_value_with_nbsp_lines(): void {
		self::assertSame(
			"Текст\n\nвторой абзац",
			$this->normalizer->normalize( "Текст\n\nвторой абзац\n\n&nbsp;\n\n&nbsp;\n" )
		);
	}

	public function test_invisible_characters_are_stripped(): void {
		self::assertSame(
			'<p>abc</p>',
			$this->normalizer->normalize( "\xEF\xBB\xBF<p>a\xE2\x80\x8Bb\xE2\x80\x8Dc</p>\r\n" )
		);
	}

	public function test_empty_paragraphs_inside_wrapper_are_trimmed(): void {
		self::assertSame(
			'<div class="x"><p>Текст</p></div>',
			$this->normalizer->normalize( '<div class="x"><p>Текст</p><p>&nbsp;</p></div><p>&nbsp;</p>' )
		);
	}

	public function test_three_inner_empty_paragraphs_collapse_to_one(): void {
		self::assertSame(
			"<p>A</p><p>&nbsp;</p>\n<p>B</p>",
			$this->normalizer->normalize( '<p>A</p><p>&nbsp;</p><p></p><p>&nbsp;</p><p>B</p>' )
		);
	}

	public function test_single_inner_spacer_is_kept(): void {
		$html = '<p>A</p><p>&nbsp;</p><p>B</p>';

		self::assertSame( $html, $this->normalizer->normalize( $html ) );
	}

	public function test_blank_lines_inside_pre_are_kept(): void {
		$html = "<p>Код:</p><pre>a = 1\n\n\n\nprint(a)</pre>";

		self::assertSame( $html, $this->normalizer->normalize( $html . "\n<p>&nbsp;</p>" ) );
	}

	public function test_only_blanks_give_empty_string(): void {
		self::assertSame( '', $this->normalizer->normalize( "<p>&nbsp;</p>\n<br>\n" ) );
	}
}
