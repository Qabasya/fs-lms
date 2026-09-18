<?php

declare( strict_types=1 );

namespace Unit\Services\Task;

use Inc\Services\Task\LatexPageShortcodeService;
use PHPUnit\Framework\TestCase;

/**
 * `[latexpage]` включает разбор формул QuickLaTeX на всей странице: ставится
 * один раз на условие, а не вокруг каждой формулы.
 */
class LatexPageShortcodeServiceTest extends TestCase {

	private LatexPageShortcodeService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->service = new LatexPageShortcodeService();
	}

	public function test_condition_with_formula_gets_shortcode(): void {
		$html = $this->service->ensure( '<p>Вычислите $$x^2 + y^2$$</p>' );

		self::assertStringStartsWith( '[latexpage]', $html );
		self::assertStringContainsString( 'x^2 + y^2', $html );
	}

	public function test_inline_formula_also_counts(): void {
		self::assertStringStartsWith( '[latexpage]', $this->service->ensure( '<p>При $n = 10$ получаем</p>' ) );
	}

	public function test_shortcode_is_not_duplicated(): void {
		$html = $this->service->ensure( '[latexpage]<p>$x$</p>' );

		self::assertSame( 1, substr_count( $html, '[latexpage]' ) );
	}

	public function test_several_formulas_give_one_shortcode(): void {
		$html = $this->service->ensure( '<p>$a$ и $b$, а также $$c$$</p>' );

		self::assertSame( 1, substr_count( $html, '[latexpage]' ) );
	}

	public function test_condition_without_formula_is_untouched(): void {
		$html = '<p>Обычное условие без формул</p>';

		self::assertSame( $html, $this->service->ensure( $html ) );
	}

	public function test_lonely_dollar_sign_is_not_a_formula(): void {
		// Цена или знак валюты не должны тянуть за собой шорткод.
		$html = '<p>Стоимость 5$ за штуку</p>';

		self::assertSame( $html, $this->service->ensure( $html ) );
	}

	public function test_empty_condition_stays_empty(): void {
		self::assertSame( '', $this->service->ensure( '' ) );
	}
}
