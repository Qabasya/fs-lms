<?php

declare( strict_types=1 );

namespace Unit\Services\Assessment;

use Inc\Services\Assessment\ArchiveTaskNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Архивные номера заданий (с «10» впереди) приводятся к живым для расчётов;
 * живые, ручные и нечисловые номера не меняются.
 */
class ArchiveTaskNumberTest extends TestCase {

	/** @return array<string, array{0:int,1:int}> */
	public static function numbers(): array {
		return array(
			'старое №3 → 103'        => array( 103, 3 ),
			'старое №1 → 101'        => array( 101, 1 ),
			'старое №17 → 117'       => array( 117, 17 ),
			'старое №27 → 127'       => array( 127, 27 ),
			'старое №17 → 1017'      => array( 1017, 17 ),
			'старое №3 → 1003'       => array( 1003, 3 ),
			'живые не меняются: 3'   => array( 3, 3 ),
			'живые не меняются: 10'  => array( 10, 10 ),
			'живые не меняются: 27'  => array( 27, 27 ),
			'100 — не архивный'      => array( 100, 100 ),
			'128 вне диапазона'      => array( 128, 128 ),
			'1028 вне диапазона'     => array( 1028, 1028 ),
			'0 — номер не определён' => array( 0, 0 ),
		);
	}

	#[DataProvider( 'numbers' )]
	public function test_base( int $number, int $expected ): void {
		self::assertSame( $expected, ( new ArchiveTaskNumber() )->base( $number ) );
	}

	public function test_base_of_string_keeps_non_numeric_values(): void {
		$archive = new ArchiveTaskNumber();

		self::assertSame( '19', $archive->baseOf( '119' ) );
		self::assertSame( '19', $archive->baseOf( ' 1019 ' ) );
		self::assertSame( '19', $archive->baseOf( '19' ) );
		self::assertSame( '19-21', $archive->baseOf( '19-21' ) );
		self::assertSame( '13.1', $archive->baseOf( '13.1' ) );
		self::assertSame( '', $archive->baseOf( '' ) );
	}
}
