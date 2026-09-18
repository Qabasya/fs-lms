<?php

declare( strict_types=1 );

namespace Unit\DTO\Task;

use Inc\DTO\Task\LegacyTaskRowDTO;
use PHPUnit\Framework\TestCase;

/**
 * Запись файла переноса заданий со старого сайта. Связка 19-21 приезжает
 * подпунктами: старый сайт держал все три задания в одном условии, а шаблон
 * «Три в одном» ждёт их по отдельным полям.
 */
class LegacyTaskRowDTOTest extends TestCase {

	public function test_row_without_subparts_stays_plain(): void {
		$row = LegacyTaskRowDTO::fromArray( array(
			'legacy_number'  => 700,
			'ege_number'     => 7,
			'condition_html' => '<p>Условие</p>',
			'answer'         => '42',
		) );

		self::assertSame( array(), $row->subparts );
		self::assertSame( '42', $row->answer );
	}

	public function test_subparts_are_parsed_per_number(): void {
		$row = LegacyTaskRowDTO::fromArray( array(
			'legacy_number' => 1900,
			'ege_number'    => 19,
			'subparts'      => array(
				'19' => array( 'condition_html' => '<p>Условие 19</p>', 'answer' => '25' ),
				'20' => array( 'condition_html' => '<p>Условие 20</p>', 'answer' => '21 24' ),
				'21' => array( 'condition_html' => '<p>Условие 21</p>', 'answer' => '20' ),
			),
		) );

		// Числовые ключи PHP хранит целыми — и `"task_{$key}_condition"` в
		// импортёре собирается одинаково хоть из int, хоть из строки.
		self::assertSame( array( 19, 20, 21 ), array_keys( $row->subparts ) );
		self::assertStringContainsString( 'Условие 20', $row->subparts[20]['condition'] );
		self::assertSame( '21 24', $row->subparts[20]['answer'] );
	}

	public function test_subpart_condition_keeps_bare_less_than(): void {
		// Та же защита, что у корневого условия: `<` формулы не должен съедаться kses.
		$row = LegacyTaskRowDTO::fromArray( array(
			'ege_number' => 19,
			'subparts'   => array(
				'19' => array( 'condition_html' => 'найдите S, при котором n<10', 'answer' => '1' ),
			),
		) );

		self::assertStringContainsString( 'n&lt;10', $row->subparts[19]['condition'] );
	}

	public function test_malformed_subparts_are_ignored(): void {
		$row = LegacyTaskRowDTO::fromArray( array(
			'ege_number' => 19,
			'subparts'   => 'не массив',
		) );

		self::assertSame( array(), $row->subparts );
	}
}
