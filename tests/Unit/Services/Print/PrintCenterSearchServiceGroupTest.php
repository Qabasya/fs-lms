<?php

declare( strict_types=1 );

namespace Unit\Services\Print;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Person\PersonDTO;
use Inc\Repositories\OptionsRepositories\PrintProgramsRepository;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Export\OneTimeDownloadService;
use Inc\Services\Group\ServiceGroupResolver;
use Inc\Services\Log\ExportLogWriter;
use Inc\Services\Print\DocxTemplateRenderer;
use Inc\Services\Print\PdfFormFiller;
use Inc\Services\Print\PrintCenterService;
use Inc\Services\Print\PrintDataCollector;
use Inc\Services\Print\TaxDeductionForm;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Центр печати: поиск ученика получает служебные группы модулей и просит репозиторий не возвращать их временных учеников.
 */
class PrintCenterSearchServiceGroupTest extends TestCase {

	private PersonRepository&MockObject $persons;

	protected function tearDown(): void {
		unset( $GLOBALS['_fs_test_filter_returns'] );
		parent::tearDown();
	}

	private function service(): PrintCenterService {
		$this->persons = $this->createMock( PersonRepository::class );

		return new PrintCenterService(
			$this->persons,
			$this->createStub( StudentRecordRepository::class ),
			$this->createStub( GroupsRepository::class ),
			$this->createStub( SubjectRepository::class ),
			$this->createStub( DocxTemplateRenderer::class ),
			$this->createStub( PrintDataCollector::class ),
			$this->createStub( OneTimeDownloadService::class ),
			$this->createStub( ExportLogWriter::class ),
			$this->createStub( PrintProgramsRepository::class ),
			$this->createStub( LogEventDispatcherInterface::class ),
			$this->createStub( PdfFormFiller::class ),
			$this->createStub( TaxDeductionForm::class ),
			new ServiceGroupResolver(),
			sys_get_temp_dir() . '/' // шаблоны документов для поиска не нужны; константа плагина в юнит-тестах не задана.
		);
	}

	public function test_the_search_hands_the_service_groups_to_the_repository(): void {
		$GLOBALS['_fs_test_filter_returns']['fs_lms_service_group_ids'] = array( 31, 32 );
		$service = $this->service();
		$this->persons->expects( self::once() )->method( 'searchStudents' )->with( 'Иван', 20, array( 31, 32 ) )->willReturn( array() );

		self::assertSame( array(), $service->searchStudents( 'Иван' ) );
	}

	public function test_a_short_query_does_not_reach_the_repository(): void {
		$service = $this->service();
		$this->persons->expects( self::never() )->method( 'searchStudents' );

		self::assertSame( array(), $service->searchStudents( 'И' ) );
	}

	public function test_without_service_groups_the_repository_gets_an_empty_list(): void {
		$service = $this->service();
		$this->persons->expects( self::once() )->method( 'searchStudents' )->with( 'Иван', 20, array() )->willReturn( array() );

		$service->searchStudents( 'Иван' );
	}
}
