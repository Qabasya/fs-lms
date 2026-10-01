<?php

declare( strict_types=1 );

namespace Unit\Modules\EgeComputer;

use Inc\Modules\EgeComputer\DTO\KegeSheetDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Лист ответов делится на таблицы по длине работы: первая заполняется до ROWS_PER_TABLE
 * строк, остаток идёт в следующую, порядок заданий сохраняется.
 */
class KegeSheetDTOTest extends TestCase {

	private function sheet( int $rows ): KegeSheetDTO {
		$list = array();
		for ( $i = 1; $i <= $rows; $i++ ) {
			$list[] = array( 'number' => (string) $i, 'score' => 1.0, 'answer' => 'a', 'correct' => 'a' );
		}

		return new KegeSheetDTO( $list, $rows, (float) $rows, (float) $rows, null, null );
	}

	/** @return array<string, array{0:int, 1:int[]}> строк => размеры таблиц */
	public static function layouts(): array {
		return array(
			'пустая работа'              => array( 0, array() ),
			'одно задание'               => array( 1, array( 1 ) ),
			'короткая работа — одна'     => array( 12, array( 12 ) ),
			'ровно на одну таблицу'      => array( 18, array( 18 ) ),
			'19 — вторая из одной строки' => array( 19, array( 18, 1 ) ),
			'обычный КЕГЭ, 27 строк'     => array( 27, array( 18, 9 ) ),
			'три №14, 30 строк'          => array( 30, array( 18, 12 ) ),
			'длинная работа, 46 строк'   => array( 46, array( 18, 18, 10 ) ),
		);
	}

	/** @param int[] $sizes */
	#[DataProvider( 'layouts' )]
	public function test_tables_split_by_length( int $rows, array $sizes ): void {
		$tables = $this->sheet( $rows )->tables();

		self::assertSame( $sizes, array_map( 'count', $tables ) );
		foreach ( $tables as $table ) {
			self::assertLessThanOrEqual( KegeSheetDTO::ROWS_PER_TABLE, count( $table ) );
		}
	}

	public function test_tables_keep_order_and_lose_nothing(): void {
		$numbers = array_merge( ...array_map( static fn( array $t ): array => array_column( $t, 'number' ), $this->sheet( 47 )->tables() ) );

		self::assertSame( array_map( 'strval', range( 1, 47 ) ), $numbers );
	}
}
