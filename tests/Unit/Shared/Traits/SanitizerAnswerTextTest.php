<?php

declare( strict_types=1 );

namespace Unit\Shared\Traits;

use Inc\Shared\Traits\Sanitizer;
use PHPUnit\Framework\TestCase;

/**
 * Ответ ученика хранится «как набрано»: теги из него НЕ вырезаются.
 *
 * Регрессия на `sanitize_textarea_field()`/`wp_kses_post()`, которые считают
 * `<` началом разметки: по информатике это оператор сравнения, и `2<3 и 5>4`
 * схлопывался в `24` — ответ уничтожался целиком, сличение с эталоном давало 0
 * баллов. Экранирование кавычек тем же путём ломало разбор JSON составного
 * ответа (19–21).
 *
 * Безопасность держится на выводе (`esc_html()` в шаблонах, `esc()` в JS,
 * `$wpdb->prepare()` в запросах) — этот тест фиксирует контракт входа, чтобы
 * «починка» санитайзера не вернула потерю ответов молча.
 */
class SanitizerAnswerTextTest extends TestCase {

	/** Носитель трейта: сам трейт protected-методы наружу не отдаёт. */
	private object $sut;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();

		$this->sut = new class() {
			use Sanitizer;

			public function answerText( string $key ): string {
				return $this->sanitizeAnswerText( $key );
			}

			public function answerValue( mixed $value ): string {
				return $this->sanitizeAnswerTextValue( $value );
			}
		};
	}

	public function test_comparison_operators_survive(): void {
		$_POST['answer_text'] = '2<3 и 5>4';

		$this->assertSame( '2<3 и 5>4', $this->sut->answerText( 'answer_text' ) );
	}

	public function test_quotes_are_not_escaped_so_composite_json_still_parses(): void {
		$json = '{"19":"да","20":"1234","21":"5"}';

		$_POST['answer_text'] = addslashes( $json );

		$decoded = json_decode( $this->sut->answerText( 'answer_text' ), true );

		$this->assertSame( array( '19' => 'да', '20' => '1234', '21' => '5' ), $decoded );
	}

	public function test_slashes_are_stripped_once_from_request(): void {
		// Значение с обратным слэшем (регулярка/путь) приходит от WP заслэшенным.
		$_POST['answer_text'] = addslashes( 'C:\\temp\\d+' );

		$this->assertSame( 'C:\\temp\\d+', $this->sut->answerText( 'answer_text' ) );
	}

	public function test_value_from_array_keeps_backslashes(): void {
		// Вызывающий уже снял слэши через unslashArray() — второй раз не снимаем.
		$this->assertSame( 'C:\\temp\\d+', $this->sut->answerValue( 'C:\\temp\\d+' ) );
	}

	public function test_line_breaks_survive_and_are_normalized(): void {
		// Табличный ответ КЕГЭ (№25/№27) — многострочный; CRLF приводится к \n.
		$this->assertSame( "1|2\n3|4", $this->sut->answerValue( "1|2\r\n3|4" ) );
	}

	public function test_control_characters_are_removed(): void {
		$this->assertSame( 'ab', $this->sut->answerValue( "a\x00\x07b" ) );
	}

	public function test_surrounding_whitespace_is_trimmed(): void {
		$this->assertSame( 'ответ', $this->sut->answerValue( "  ответ \n" ) );
	}

	public function test_non_string_input_becomes_empty_string(): void {
		$this->assertSame( '', $this->sut->answerValue( array( 'a' ) ) );
		$this->assertSame( '', $this->sut->answerValue( null ) );
	}

	public function test_missing_key_becomes_empty_string(): void {
		$this->assertSame( '', $this->sut->answerText( 'answer_text' ) );
	}
}
