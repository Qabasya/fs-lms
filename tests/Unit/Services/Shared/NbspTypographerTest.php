<?php

declare( strict_types=1 );

namespace Unit\Services\Shared;

use Inc\Services\Shared\NbspTypographer;
use PHPUnit\Framework\TestCase;

/**
 * Неразрывные пробелы после предлогов: только текст, код и атрибуты не трогаются.
 */
class NbspTypographerTest extends TestCase {

	private const NB = "\u{00A0}";

	private NbspTypographer $t;

	protected function setUp(): void {
		$this->t = new NbspTypographer();
	}

	public function test_short_words_bind_to_next_word(): void {
		self::assertSame(
			'Игроки ходят по' . self::NB . 'очереди и' . self::NB . 'в' . self::NB . 'конце для' . self::NB . 'победы',
			$this->t->text( 'Игроки ходят по очереди и в конце для победы' )
		);
	}

	public function test_long_words_and_hyphenated_are_left_alone(): void {
		self::assertSame( 'из-за погоды, программа запуска', $this->t->text( 'из-за погоды, программа запуска' ) );
	}

	public function test_dash_and_numero(): void {
		self::assertSame( 'Ответ' . self::NB . '— задание №' . self::NB . '19', $this->t->text( 'Ответ — задание № 19' ) );
	}

	public function test_word_before_tag_binds_across_it(): void {
		self::assertSame( '<p>на' . self::NB . '<strong>доске</strong></p>', $this->t->html( '<p>на <strong>доске</strong></p>' ) );
	}

	public function test_code_script_and_attributes_untouched(): void {
		$html = '<pre><code>if a in b: print(a)</code> x in y</pre><script>if (a in b) go()</script>'
			. '<a title="в город" href="/a">в город</a><code>a in b</code><textarea>в поле</textarea>';

		$out = $this->t->html( $html );

		self::assertStringContainsString( '<pre><code>if a in b: print(a)</code> x in y</pre>', $out );
		self::assertStringContainsString( '<script>if (a in b) go()</script>', $out );
		self::assertStringContainsString( 'title="в город"', $out );
		self::assertStringContainsString( '>в' . self::NB . 'город</a>', $out );
		self::assertStringContainsString( '<code>a in b</code>', $out );
		self::assertStringContainsString( '<textarea>в поле</textarea>', $out );
	}

	public function test_idempotent(): void {
		$once = $this->t->html( '<p>Два игрока, Петя и Ваня, играют в игру — по очереди.</p>' );

		self::assertSame( $once, $this->t->html( $once ) );
	}
}
