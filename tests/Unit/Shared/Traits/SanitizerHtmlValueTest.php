<?php

declare( strict_types=1 );

namespace Unit\Shared\Traits;

use Inc\Shared\Traits\Sanitizer;
use PHPUnit\Framework\TestCase;

/**
 * HTML-значение из массива, уже прошедшего `unslashArray()`, не должно терять `\`.
 *
 * Регрессия: второй `wp_unslash()` превращал `$\vec{V}$` в `$vec{V}$`, и формула
 * в условии задания не отображалась (в статьях, идущих другим путём, всё было в порядке).
 */
class SanitizerHtmlValueTest extends TestCase {

	private object $sut;

	protected function setUp(): void {
		parent::setUp();

		$this->sut = new class() {
			use Sanitizer;

			public function htmlValue( mixed $value ): string {
				return $this->sanitizeHtmlValue( $value );
			}

			public function textValue( mixed $value ): string {
				return $this->sanitizeTextValue( $value );
			}

			public function multilineValue( mixed $value ): string {
				return $this->sanitizeMultilineTextValue( $value );
			}
		};
	}

	public function test_latex_backslashes_survive(): void {
		$html = '<p>$\vec{V} = (V_x; V_y)$,</p>';

		$this->assertSame( $html, $this->sut->htmlValue( $html ) );
	}

	public function test_text_values_keep_backslashes(): void {
		$this->assertSame( 'C:\dir \alpha', $this->sut->textValue( 'C:\dir \alpha' ) );
		$this->assertSame( "a \\n\nb", $this->sut->multilineValue( "a \\n\nb" ) );
	}
}
