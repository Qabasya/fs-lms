<?php

declare( strict_types=1 );

namespace Unit\Modules\EgeComputer;

use Inc\DTO\Assessment\AssessmentDTO;
use Inc\DTO\Assessment\AttemptDTO;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Assessment\ScoringPolicy;
use Inc\Modules\EgeComputer\Callbacks\KegeFilesZipCallbacks;
use Inc\Modules\EgeComputer\Callbacks\PreviewResultCallbacks;
use Inc\Modules\EgeComputer\Config\EgeComputerConfig;
use Inc\Modules\EgeComputer\DTO\KegeSheetDTO;
use Inc\Modules\EgeComputer\EgeComputerModule;
use Inc\Modules\EgeComputer\Services\KegeResultSheetService;
use Inc\Services\Assessment\ArchiveTaskNumber;
use Inc\Services\Assessment\AttemptRevealPolicy;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Лист результата станции: ученику открывается по утверждению, а тому, кто смотрит работу
 * как преподаватель группы (`forceReveal`), — сразу.
 */
class EgeComputerModuleRevealTest extends TestCase {

	private KegeResultSheetService&MockObject $sheet;
	private EgeComputerModule $module;

	protected function setUp(): void {
		parent::setUp();
		$this->sheet  = $this->createMock( KegeResultSheetService::class );
		$this->module = new EgeComputerModule(
			$this->createMock( EgeComputerConfig::class ),
			$this->sheet,
			$this->createMock( PreviewResultCallbacks::class ),
			new AttemptRevealPolicy(),
			new ArchiveTaskNumber(),
			$this->createMock( KegeFilesZipCallbacks::class ),
		);
	}

	private function assessment(): AssessmentDTO {
		return new AssessmentDTO(
			id: 9, subjectKey: 'inf', title: 'Экзамен', taskIds: array( 1 ), timeLimit: 0, attemptsAllowed: 0, passScore: 0.0,
			scoringPolicy: ScoringPolicy::Highest, status: 'publish', kind: AssessmentKind::EgeComputer,
			taskPoints: array(), scoreMap: array(),
		);
	}

	private function unapproved(): AttemptDTO {
		return AttemptDTO::fromArray( array(
			'id' => 5, 'assessment_id' => 9, 'student_person_id' => 11, 'attempt_number' => 1, 'status' => 'graded',
			'started_at' => '2026-09-29 10:00:00', 'deadline_at' => '2026-09-29 13:00:00',
		) );
	}

	public function test_unapproved_attempt_is_hidden_from_the_student(): void {
		$this->sheet->expects( self::once() )->method( 'build' )
			->with( self::anything(), self::anything(), self::anything(), false )
			->willReturn( KegeSheetDTO::blank() );

		$this->module->buildResultSheet( null, $this->assessment(), $this->unapproved(), array() );
	}

	public function test_group_manager_sees_unapproved_attempt(): void {
		$this->sheet->expects( self::once() )->method( 'build' )
			->with( self::anything(), self::anything(), self::anything(), true )
			->willReturn( KegeSheetDTO::blank() );

		$this->module->buildResultSheet( null, $this->assessment(), $this->unapproved(), array(), true );
	}
}
