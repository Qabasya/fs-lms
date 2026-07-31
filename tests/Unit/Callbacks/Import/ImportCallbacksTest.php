<?php

declare( strict_types=1 );

// Оверрайд нативной is_uploaded_file() в неймспейсе колбэка: PHP резолвит
// неквалифицированный вызов сначала в текущем неймспейсе, поэтому тест
// может подсунуть валидный «загруженный» файл без реального upload.
namespace Inc\Callbacks\Import {
	if ( ! function_exists( 'Inc\Callbacks\Import\is_uploaded_file' ) ) {
		function is_uploaded_file( string $filename ): bool {
			return $GLOBALS['_fs_test_is_uploaded'] ?? true;
		}
	}
}

namespace Unit\Callbacks\Import {

	use Inc\Callbacks\Import\ImportCallbacks;
	use Inc\DTO\Import\ImportReportDTO;
	use Inc\Enums\Import\ImportMode;
	use Inc\Services\Import\EnrolledStudentRowImporter;
	use Inc\Services\Import\ImportService;
use Inc\Services\Import\RowImporterRegistry;
	use Inc\Services\Import\StudentRowImporter;
	use InvalidArgumentException;
	use PHPUnit\Framework\TestCase;

	class ImportCallbacksTest extends TestCase {

		private StudentRowImporter $archiveImporter;
		private EnrolledStudentRowImporter $enrolledImporter;
		private ImportService $importService;
		private ImportCallbacks $cb;

		protected function setUp(): void {
			parent::setUp();
			fs_test_reset_ajax();
			$GLOBALS['_fs_test_is_uploaded'] = true;

			$this->archiveImporter  = $this->createMock( StudentRowImporter::class );
			$this->enrolledImporter = $this->createMock( EnrolledStudentRowImporter::class );
			$this->importService    = $this->createMock( ImportService::class );

			$this->cb = new ImportCallbacks(
			new RowImporterRegistry( $this->archiveImporter, $this->enrolledImporter ),
			$this->importService
		);
		}

		protected function tearDown(): void {
			unset( $GLOBALS['_fs_test_is_uploaded'] );
			$_FILES = array();
			parent::tearDown();
		}

		/** @param array<string,mixed> $post Дополнение к валидному $_POST */
		private function preparePostAndFile( array $post = array() ): void {
			$_POST = array_merge(
				array(
					'subject_key' => 'math',
					'period_id'   => '2024',
				),
				$post
			);

			$_FILES = array(
				'file' => array(
					'name'     => 'students.csv',
					'error'    => UPLOAD_ERR_OK,
					'size'     => 1024,
					'tmp_name' => '/tmp/php-upload-test',
				),
			);
		}

		public function test_enrolled_mode_selects_enrolled_importer_and_passes_send_emails(): void {
			$this->preparePostAndFile( array( 'mode' => 'enrolled', 'send_emails' => '1' ) );

			$this->importService->expects( $this->once() )
				->method( 'run' )
				->with(
					$this->identicalTo( $this->enrolledImporter ),
					ImportMode::Enrolled,
					true,
					'math',
					'2024',
					'/tmp/php-upload-test',
					false
				)
				->willReturn( new ImportReportDTO( false ) );

			$r = fs_test_capture_json( fn() => $this->cb->ajaxImportStudentsCsv() );

			self::assertTrue( $r->success );
			self::assertArrayHasKey( 'credentials', $r->payload );
		}

		public function test_default_mode_is_archive_without_emails(): void {
			$this->preparePostAndFile( array( 'dry_run' => '1' ) );

			$this->importService->expects( $this->once() )
				->method( 'run' )
				->with(
					$this->identicalTo( $this->archiveImporter ),
					ImportMode::Archive,
					false,
					'math',
					'2024',
					'/tmp/php-upload-test',
					true
				)
				->willReturn( new ImportReportDTO( true ) );

			$r = fs_test_capture_json( fn() => $this->cb->ajaxImportStudentsCsv() );

			self::assertTrue( $r->success );
			self::assertTrue( $r->payload['dry_run'] );
		}

		public function test_missing_subject_fails_before_import(): void {
			$this->preparePostAndFile();
			unset( $_POST['subject_key'] );

			$this->importService->expects( $this->never() )->method( 'run' );

			$r = fs_test_capture_json( fn() => $this->cb->ajaxImportStudentsCsv() );

			self::assertFalse( $r->success );
			self::assertSame( 'Не выбран предмет.', $r->payload );
		}

		public function test_missing_period_fails_before_import(): void {
			$this->preparePostAndFile();
			unset( $_POST['period_id'] );

			$this->importService->expects( $this->never() )->method( 'run' );

			$r = fs_test_capture_json( fn() => $this->cb->ajaxImportStudentsCsv() );

			self::assertFalse( $r->success );
			self::assertSame( 'Не выбран учебный период.', $r->payload );
		}

		public function test_non_csv_extension_rejected(): void {
			$this->preparePostAndFile();
			$_FILES['file']['name'] = 'students.xlsx';

			$this->importService->expects( $this->never() )->method( 'run' );

			$r = fs_test_capture_json( fn() => $this->cb->ajaxImportStudentsCsv() );

			self::assertFalse( $r->success );
		}

		public function test_file_error_from_service_returned_as_error(): void {
			$this->preparePostAndFile();

			$this->importService->method( 'run' )
				->willThrowException( new InvalidArgumentException( 'Файл пуст или не содержит строк данных.' ) );

			$r = fs_test_capture_json( fn() => $this->cb->ajaxImportStudentsCsv() );

			self::assertFalse( $r->success );
			self::assertSame( 'Файл пуст или не содержит строк данных.', $r->payload );
		}
	}
}
