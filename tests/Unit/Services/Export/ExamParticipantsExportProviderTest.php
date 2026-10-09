<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Export;

use Inc\DTO\Export\CsvColumn;
use Inc\Services\Exam\ExamConductService;
use Inc\Services\Export\ExamParticipantsExportProvider;
use PHPUnit\Framework\TestCase;

/**
 * CSV участников сеанса (8.9.2): без контактов, выборка по участиям, ЕГЭ — вторичный балл, ОГЭ — оценка.
 */
class ExamParticipantsExportProviderTest extends TestCase {

	public function test_columns_have_no_contacts(): void {
		$provider = new ExamParticipantsExportProvider( $this->createMock( ExamConductService::class ) );

		$headers = array_map( static fn ( CsvColumn $c ): string => $c->header, $provider->columns() );

		self::assertSame( array( 'ФИО', 'Источник', 'Сеанс', 'Статус', 'Первичный балл', 'Вторичный балл / отметка' ), $headers );
		foreach ( $headers as $header ) {
			self::assertDoesNotMatchRegularExpression( '/телефон|мессенджер|ссылк|email|e-mail/iu', $header );
		}
	}

	public function test_rows_limited_to_selected_participations(): void {
		$conduct = $this->createMock( ExamConductService::class );
		$conduct->expects( self::once() )->method( 'exportRows' )->with( 10, array( 'participation_ids' => array( 5, 6 ), 'actor_user_id' => 10 ) )->willReturn( array() );

		$provider = new ExamParticipantsExportProvider( $conduct );

		self::assertSame( array(), $provider->rows( array( 'participation_ids' => array( 5, 6 ), 'actor_user_id' => 10 ) ) );
	}

	public function test_ege_row_has_secondary_oge_row_has_grade(): void {
		$ege = array( 'name' => 'Иванов Пётр', 'source' => 'Школа 5', 'session' => '2026-03-10 10:00', 'status' => 'Работа сдана', 'primary' => '20 / 29', 'secondary' => '84 / 100' );
		$oge = array( 'name' => 'Петров Иван', 'source' => 'Школа 6', 'session' => '2026-03-10 10:00', 'status' => 'Работа сдана', 'primary' => '15 / 19', 'secondary' => 'оценка 4' );
		$provider = new ExamParticipantsExportProvider( $this->createMock( ExamConductService::class ) );

		$columns = $provider->columns();
		$cells   = static fn ( array $row ): array => array_map( static fn ( CsvColumn $c ): string => ( $c->extractor )( $row ), $columns );

		self::assertSame( '84 / 100', $cells( $ege )[5] );
		self::assertSame( 'оценка 4', $cells( $oge )[5] );
		self::assertSame( '20 / 29', $cells( $ege )[4] );
	}
}
