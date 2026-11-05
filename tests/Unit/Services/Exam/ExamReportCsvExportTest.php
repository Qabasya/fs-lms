<?php

declare( strict_types=1 );

namespace Unit\Services\Exam;

use Inc\Services\Exam\ExamReportCsvExport;
use PHPUnit\Framework\TestCase;

/**
 * CSV-выгрузка школьного отчёта: BOM, разделитель «;», колонки заданий, полное ФИО,
 * «Средний» по окончательным результатам и сводка по заданиям (семантика статистики проведения).
 */
class ExamReportCsvExportTest extends TestCase {

	/** @return array{columns: list<string>, rows: list<array{name: string, cells: array<string, array{status: string, score: string, class: string}>, primary: string, secondary: string}>, footer: array{average_primary: string, average_secondary: string, attempts: list<string>, correct: list<string>, attempts_share: list<string>, correct_share: list<string>}>} */
	private function table(): array {
		return array(
			'columns' => array( '1', '2', '3' ),
			'rows'    => array(
				array(
					'name'      => 'Иванов Пётр',
					'primary'   => '1',
					'secondary' => '62',
					'cells'     => array(
						'1' => array( 'status' => 'correct', 'score' => '1', 'class' => 'is-ok' ),
						'2' => array( 'status' => 'incorrect', 'score' => '0', 'class' => 'is-zero' ),
						'3' => array( 'status' => 'unanswered', 'score' => '', 'class' => '' ),
					),
				),
				array(
					'name'      => 'Сидорова Анна',
					'primary'   => '1',
					'secondary' => '',
					'cells'     => array(
						'1' => array( 'status' => 'correct', 'score' => '1', 'class' => 'is-ok' ),
						'2' => array( 'status' => 'pending', 'score' => '', 'class' => '' ),
					),
				),
			),
			'footer'  => array(
				'average_primary'   => '1',
				'average_secondary' => '62',
				'attempts'          => array( '2', '1', '0' ),
				'correct'           => array( '2', '0', '0' ),
				'attempts_share'    => array( '100', '50', '0' ),
				'correct_share'     => array( '100', '0', '' ),
			),
		);
	}

	/** @return list<string> */
	private function lines(): array {
		$csv = ( new ExamReportCsvExport( new \Inc\Services\Export\CsvExportService( $this->createStub( \Inc\Services\Export\OneTimeDownloadService::class ) ) ) )->build( $this->table() );

		self::assertStringStartsWith( "\xEF\xBB\xBF", $csv, 'BOM для Excel' );

		return explode( "\n", trim( substr( $csv, 3 ), "\n" ) );
	}

	public function test_header_and_student_rows(): void {
		$lines = $this->lines();

		self::assertSame( 'ФИО;1;2;3;"Первичный балл";"Вторичный балл"', $lines[0] );
		self::assertSame( '"Иванов Пётр";1;0;;1;62', $lines[1], 'не решено — пустая ячейка, полное ФИО' );
		self::assertSame( '"Сидорова Анна";1;;;1;', $lines[2], 'на проверке — пустая ячейка, вторичный без окончательного результата пуст' );
	}

	public function test_formula_in_name_is_neutralized(): void {
		$table = $this->table();
		$table['rows'][0]['name'] = '=HYPERLINK("https://example.com")';
		$export = new ExamReportCsvExport( new \Inc\Services\Export\CsvExportService( $this->createStub( \Inc\Services\Export\OneTimeDownloadService::class ) ) );
		self::assertStringContainsString( "'=HYPERLINK", $export->build( $table ) );
	}

	public function test_footer_has_average_and_task_summary(): void {
		$lines = $this->lines();

		self::assertSame( 'Средний;;;;1;62', $lines[3], 'средний — только по окончательным результатам' );
		self::assertSame( 'Попытки;2;1;0;;', $lines[4], 'попытки = дан ответ (на проверке не считается)' );
		self::assertSame( '"Верные решения";2;0;0;;', $lines[5] );
		self::assertSame( '"Процент попыток, %";100;50;0;;', $lines[6], 'доля попыток от числа учеников' );
		self::assertSame( '"Процент верных, %";100;0;;;', $lines[7], 'доля верных от попыток; попыток нет — пусто' );
	}

	public function test_empty_report_has_header_only_with_empty_summary(): void {
		$csv = ( new ExamReportCsvExport( new \Inc\Services\Export\CsvExportService( $this->createStub( \Inc\Services\Export\OneTimeDownloadService::class ) ) ) )->build(
			array(
				'columns' => array(),
				'rows'    => array(),
				'footer'  => array(
					'average_primary'   => '',
					'average_secondary' => '',
					'attempts'          => array(),
					'correct'           => array(),
					'attempts_share'    => array(),
					'correct_share'     => array(),
				),
			)
		);

		self::assertSame(
			"\xEF\xBB\xBF" . "ФИО;\"Первичный балл\";\"Вторичный балл\"\nСредний;;\nПопытки;;\n\"Верные решения\";;\n\"Процент попыток, %\";;\n\"Процент верных, %\";;",
			trim( $csv, "\n" )
		);
	}
}
