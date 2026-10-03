<?php declare( strict_types=1 );

namespace Tests\Unit\Modules\EgeComputer;

use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Exam\ExamDirection;
use Inc\Modules\EgeComputer\Config\KegeScaleConfig;
use Inc\Modules\EgeComputer\Config\OgeScaleConfig;
use Inc\Modules\EgeComputer\Config\StationExamConfig;
use Inc\Modules\EgeComputer\EgeComputerModule;
use PHPUnit\Framework\TestCase;

class ExamFormatsProviderTest extends TestCase {

	public function test_kege_format_has_correct_values(): void {
		$module = new EgeComputerModule(
			new \Inc\Modules\EgeComputer\Config\EgeComputerConfig(),
			$this->createStub( \Inc\Modules\EgeComputer\Services\KegeResultSheetService::class ),
			$this->createStub( \Inc\Modules\EgeComputer\Callbacks\PreviewResultCallbacks::class ),
			$this->createStub( \Inc\Services\Assessment\AttemptRevealPolicy::class ),
			$this->createStub( \Inc\Services\Assessment\ArchiveTaskNumber::class ),
			$this->createStub( \Inc\Modules\EgeComputer\Callbacks\KegeFilesZipCallbacks::class ),
		);

		$formats = $module->provideExamFormats( [] );

		$kege = null;
		foreach ( $formats as $format ) {
			if ( $format->kind === AssessmentKind::EgeComputer ) {
				$kege = $format;
				break;
			}
		}

		$this->assertNotNull( $kege );
		$this->assertSame( ExamDirection::Ege, $kege->direction );
		$this->assertSame( 27, $kege->unitCount );
		$this->assertSame( 29, $kege->primaryMax );
		$this->assertSame( 100, $kege->secondaryMax );
		$this->assertSame( 0, $kege->gradeMax );
		$this->assertSame( 235, $kege->durationMinutes );
		$this->assertSame( 2, $kege->unitMax( 26 ) );
		$this->assertSame( 1, $kege->unitMax( 1 ) );
		$this->assertSame( 72, $kege->translate( 18 ) );
		$this->assertSame( 83, $kege->translate( 22 ) );
	}

	public function test_oge_format_has_correct_values(): void {
		$module = new EgeComputerModule(
			new \Inc\Modules\EgeComputer\Config\EgeComputerConfig(),
			$this->createStub( \Inc\Modules\EgeComputer\Services\KegeResultSheetService::class ),
			$this->createStub( \Inc\Modules\EgeComputer\Callbacks\PreviewResultCallbacks::class ),
			$this->createStub( \Inc\Services\Assessment\AttemptRevealPolicy::class ),
			$this->createStub( \Inc\Services\Assessment\ArchiveTaskNumber::class ),
			$this->createStub( \Inc\Modules\EgeComputer\Callbacks\KegeFilesZipCallbacks::class ),
		);

		$formats = $module->provideExamFormats( [] );

		$oge = null;
		foreach ( $formats as $format ) {
			if ( $format->kind === AssessmentKind::OgeComputer ) {
				$oge = $format;
				break;
			}
		}

		$this->assertNotNull( $oge );
		$this->assertSame( ExamDirection::Oge, $oge->direction );
		$this->assertSame( 16, $oge->unitCount );
		$this->assertSame( 21, $oge->primaryMax );
		$this->assertNull( $oge->secondaryMax );
		$this->assertSame( 5, $oge->gradeMax );
		$this->assertSame( 150, $oge->durationMinutes );
	}
}
