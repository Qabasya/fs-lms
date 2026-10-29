<?php

declare( strict_types=1 );

namespace Unit\Services\Task;

use Inc\Services\Task\ConditionHtmlNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
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

	public function test_formula_spaces_become_non_breaking(): void {
		$nb = "\u{00A0}";
		self::assertSame(
			"<p>Вычислите F{$nb}={$nb}x{$nb}+{$nb}+{$nb}y{$nb}-{$nb}z при x больше нуля.</p>",
			$this->normalizer->normalize( '<p>Вычислите F = x + + y - z при x больше нуля.</p>' )
		);
	}

	public function test_comparison_entities_are_glued_and_prose_is_not(): void {
		$nb = "\u{00A0}";
		self::assertSame(
			"<p>Если A{$nb}&lt;{$nb}B, то выведите слово.</p>",
			$this->normalizer->normalize( '<p>Если A &lt; B, то выведите слово.</p>' )
		);
	}

	public function test_formula_inside_code_pre_tags_and_math_is_untouched(): void {
		$html = '<pre>a = b + c</pre><code>x = y</code><p>\\(a = b + c\\)</p>';
		self::assertSame( $html, $this->normalizer->normalize( $html ) );
	}

	public function test_logic_formula_with_prefix_negation_is_glued_whole(): void {
		$nb = "\u{00A0}";
		$in = '<p>F = ¬((¬ x ∨ y) ∧ ¬ w) ∨ ¬(z ∧ ¬(y ∧ w)) для Миши</p>';

		self::assertSame(
			'<p>' . str_replace( ' ', $nb, 'F = ¬((¬ x ∨ y) ∧ ¬ w) ∨ ¬(z ∧ ¬(y ∧ w))' ) . ' для Миши</p>',
			$this->normalizer->normalize( $in )
		);
	}

	public function test_words_without_operator_are_not_glued(): void {
		$html = '<p>Даны числа A B и 5 7 в таблице.</p>';
		self::assertSame( $html, $this->normalizer->normalize( $html ) );
	}

	public function test_mixed_nbsp_and_space_separators_are_all_non_breaking(): void {
		$nb = "\u{00A0}";
		self::assertSame(
			"<p>F{$nb}={$nb}{$nb}¬(x{$nb}∨{$nb}y)</p>",
			$this->normalizer->normalize( "<p>F{$nb}={$nb} ¬(x ∨ y)</p>" )
		);
	}

	#[DataProvider( 'logicFormulas' )]
	public function test_every_logic_operator_glues_the_formula( string $formula ): void {
		$nb = "\u{00A0}";
		self::assertSame(
			'<p>' . str_replace( ' ', $nb, $formula ) . ' верно</p>',
			$this->normalizer->normalize( "<p>{$formula} верно</p>" )
		);
	}

	/** @return array<string, array{string}> */
	public static function logicFormulas(): array {
		return array(
			'импликация →'       => array( '(x → y) ∧ ¬ z' ),
			'импликация ⇒'       => array( 'x ⇒ y ∨ z' ),
			'эквиваленция ≡'     => array( '(x ≡ y) ∨ w' ),
			'эквиваленция ↔'     => array( 'x ↔ ¬ y' ),
			'исключающее ИЛИ ⊕'  => array( 'x ⊕ y ⊕ z' ),
			'штрих Шеффера ↑'    => array( 'x ↑ y' ),
			'стрелка Пирса ↓'    => array( 'x ↓ y' ),
			'конъюнкция ·'       => array( 'x · y ∨ z' ),
			'ASCII-стрелка'      => array( 'x -&gt; y' ),
			'ASCII-конъюнкция'   => array( 'x /\ y' ),
			'ASCII-дизъюнкция'   => array( 'x \/ y' ),
		);
	}
}
