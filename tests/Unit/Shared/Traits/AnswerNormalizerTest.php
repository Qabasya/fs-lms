<?php

declare( strict_types=1 );

namespace Unit\Shared\Traits;

use Inc\Shared\Traits\AnswerNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Tasks.md, п. 4: сверка ответа не зависит ни от регистра, ни от пробелов —
 * «Макс: 2; 3» и «Макс:2;3» это один ответ. Нормализатор общий для чекеров,
 * FillTextParser, BatchCheckService и листа ответов станции КЕГЭ: если он
 * разъедется, авто-проверка и лист начнут расходиться в вердикте на одном и
 * том же ответе.
 *
 * Нормализация касается ТОЛЬКО сравнения — показанный ответ остаётся сырым.
 */
class AnswerNormalizerTest extends TestCase {

	/** Носитель трейта: сам трейт protected-метод наружу не отдаёт. */
	private object $sut;

	protected function setUp(): void {
		parent::setUp();

		$this->sut = new class() {
			use AnswerNormalizer;

			public function run( string $value ): string {
				return self::normalizeAnswer( $value );
			}
		};
	}

	public function test_inner_spaces_removed(): void {
		self::assertSame( $this->sut->run( 'Макс: 2; 3' ), $this->sut->run( 'Макс:2;3' ) );
	}

	public function test_line_breaks_removed(): void {
		self::assertSame( $this->sut->run( "первая\nвторая" ), $this->sut->run( 'перваявторая' ) );
	}

	public function test_crlf_and_tabs_removed(): void {
		self::assertSame( $this->sut->run( 'абв' ), $this->sut->run( " а\r\n б\tв " ) );
	}

	public function test_case_is_ignored(): void {
		self::assertSame( $this->sut->run( 'ОТВЕТ' ), $this->sut->run( 'ответ' ) );
	}

	public function test_non_breaking_space_removed(): void {
		self::assertSame( $this->sut->run( '2;3' ), $this->sut->run( "2;\u{00A0}3" ) );
	}

	public function test_different_answers_stay_different(): void {
		self::assertNotSame( $this->sut->run( 'Макс: 2; 3' ), $this->sut->run( 'Макс: 2; 4' ) );
	}

	public function test_whitespace_only_becomes_empty(): void {
		self::assertSame( '', $this->sut->run( "  \n\t " ) );
	}
}
