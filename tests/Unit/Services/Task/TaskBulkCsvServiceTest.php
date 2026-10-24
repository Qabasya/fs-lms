<?php

declare( strict_types=1 );

namespace Unit\Services\Task;

use Inc\DTO\Subject\TaxonomyDataDTO;
use Inc\Managers\Wp\PostManager;
use Inc\Managers\Wp\TermManager;
use Inc\Repositories\OptionsRepositories\TaxonomyRepository;
use Inc\Services\Export\CsvExportService;
use Inc\Services\Export\OneTimeDownloadService;
use Inc\Services\Task\TaskBulkCsvService;
use PHPUnit\Framework\TestCase;

/**
 * Формат CSV массовой правки заданий: разбор файла и выгрузка снимка.
 */
class TaskBulkCsvServiceTest extends TestCase {

	private TaskBulkCsvService $service;

	protected function setUp(): void {
		parent::setUp();

		$taxonomies = $this->createMock( TaxonomyRepository::class );
		$taxonomies->method( 'getBySubject' )->willReturn( array(
			new TaxonomyDataDTO( 'inf_task_number', 'Номер', 'inf', is_protected: true ),
			new TaxonomyDataDTO( 'inf_author', 'Автор', 'inf' ),
			new TaxonomyDataDTO( 'inf_year', 'Год', 'inf' ),
		) );

		$this->service = new TaskBulkCsvService(
			$this->createMock( PostManager::class ),
			$this->createMock( TermManager::class ),
			$taxonomies,
			new CsvExportService( $this->createMock( OneTimeDownloadService::class ) )
		);
	}

	public function test_parse_reads_fixed_and_taxonomy_columns(): void {
		$csv = "\xEF\xBB\xBFСтарый номер,Новый номер,Название,Дата публикации,Номер задания,Таксономия: Автор [inf_author]\n"
			. "1000,1200,Авторское задание,2021-05-01 10:00,1,Иванов | Петров\n";

		$rows = $this->service->parse( $csv, 'inf' );

		self::assertCount( 1, $rows );
		self::assertSame( '1000', $rows[0]->oldNumber );
		self::assertSame( '1200', $rows[0]->newNumber );
		self::assertSame( 'Авторское задание', $rows[0]->title );
		self::assertSame( '2021-05-01 10:00', $rows[0]->date );
		self::assertSame( '1', $rows[0]->taskNumber );
		self::assertSame( array( 'inf_author' => array( 'Иванов', 'Петров' ) ), $rows[0]->terms );
		self::assertSame( 2, $rows[0]->line );
	}

	public function test_parse_understands_semicolon_excel_files(): void {
		$rows = $this->service->parse( "Старый номер;Новый номер\n1000;1200\n", 'inf' );

		self::assertSame( '1200', $rows[0]->newNumber );
	}

	public function test_empty_cell_means_no_change_and_dash_clears_taxonomy(): void {
		$csv  = "Старый номер,Таксономия: Автор [inf_author],Таксономия: Год [inf_year]\n1000,-,\n";
		$rows = $this->service->parse( $csv, 'inf' );

		self::assertSame( array( 'inf_author' => array() ), $rows[0]->terms, 'Пустой год не попадает в правку, «-» очищает автора.' );
	}

	public function test_rows_wrapped_whole_in_quotes_by_the_editor_are_unwrapped(): void {
		// Так сохраняет часть редакторов: строка целиком — одна ячейка, внутренние кавычки удвоены.
		$csv = "\xEF\xBB\xBF\"Старый номер,\"\"Новый номер\"\",Название,\"\"Таксономия: Автор [inf_author]\"\"\"\r\n"
			. "\"12001,,\"\"Типовые варианты\"\",\"\"Крылов С. С.\"\"\"\r\n";

		$rows = $this->service->parse( $csv, 'inf' );

		self::assertCount( 1, $rows );
		self::assertSame( '12001', $rows[0]->oldNumber );
		self::assertSame( '', $rows[0]->newNumber );
		self::assertSame( 'Типовые варианты', $rows[0]->title );
		self::assertSame( array( 'inf_author' => array( 'Крылов С. С.' ) ), $rows[0]->terms );
	}

	public function test_blank_lines_are_skipped(): void {
		$rows = $this->service->parse( "Старый номер,Новый номер\n1000,1200\n\n,\n1001,1201\n", 'inf' );

		self::assertCount( 2, $rows );
		self::assertSame( '1001', $rows[1]->oldNumber );
	}

	public function test_unknown_column_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageMatches( '/Неизвестная колонка/' );

		$this->service->parse( "Старый номер,Мусор\n1000,x\n", 'inf' );
	}

	public function test_taxonomy_of_another_subject_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->service->parse( "Старый номер,Таксономия: Автор [math_author]\n1000,x\n", 'inf' );
	}

	public function test_missing_old_number_column_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageMatches( '/Старый номер/' );

		$this->service->parse( "Новый номер\n1200\n", 'inf' );
	}

	public function test_empty_file_and_file_without_rows_are_rejected(): void {
		foreach ( array( '', "Старый номер,Новый номер\n" ) as $csv ) {
			try {
				$this->service->parse( $csv, 'inf' );
				self::fail( 'Ожидалась ошибка' );
			} catch ( \InvalidArgumentException $e ) {
				self::assertNotSame( '', $e->getMessage() );
			}
		}
	}

	public function test_formula_guard_apostrophe_added_by_export_is_stripped(): void {
		$rows = $this->service->parse( "Старый номер,Название\n1000,'-Минус в начале\n", 'inf' );

		self::assertSame( '-Минус в начале', $rows[0]->title );
	}

	public function test_strip_prefix_leaves_only_the_generated_part(): void {
		self::assertSame( 'Демоверсия 2021', TaskBulkCsvService::stripPrefix( '№ 1000. Демоверсия 2021' ) );
		self::assertSame( 'Без префикса', TaskBulkCsvService::stripPrefix( 'Без префикса' ) );
	}

	public function test_snapshot_csv_maps_old_number_to_new_data(): void {
		$csv = $this->service->buildSnapshotCsv( array(
			'id'    => 7,
			'items' => array(
				array(
					'post_id'  => 55,
					'old_slug' => '1000',
					'new_slug' => '1200',
					'before'   => array( 'title' => '№ 1000. Демо', 'post_date' => '2021-01-01 10:00:00' ),
					'after'    => array( 'title' => '№ 1200. Авторское', 'post_date' => '2022-02-02 12:30:00' ),
					'summary'  => 'номер 1000 → 1200',
				),
			),
		) );

		self::assertStringContainsString( 'Снимок №', $csv );
		self::assertStringContainsString( '7,55,1000,1200,"№ 1000. Демо","№ 1200. Авторское","2021-01-01 10:00","2022-02-02 12:30","номер 1000 → 1200"', $csv );
	}
}
