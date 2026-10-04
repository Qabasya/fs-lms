<?php

declare( strict_types=1 );

namespace Unit\Services\Assessment;

use Inc\DTO\Assessment\AssessmentDTO;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Assessment\ScoringPolicy;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Services\Assessment\AssessmentKindGuard;
use Inc\Services\Course\ContentUsageService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Станцию экзамена нельзя сделать из работы, которая уже стоит шагом урока (6.6.3); Control и уже существующие станции не затрагиваются.
 */
#[AllowMockObjectsWithoutExpectations]
class AssessmentKindGuardTest extends TestCase {

	private AssessmentManager&MockObject $assessments;
	private ContentUsageService&MockObject $usage;
	private AssessmentKindGuard $guard;

	protected function setUp(): void {
		parent::setUp();
		$this->assessments = $this->createMock( AssessmentManager::class );
		$this->usage       = $this->createMock( ContentUsageService::class );
		$this->guard       = new AssessmentKindGuard( $this->assessments, $this->usage );
	}

	private function current( ?AssessmentKind $kind ): void {
		$this->assessments->method( 'get' )->willReturn( null === $kind ? null : new AssessmentDTO(
			id: 7, subjectKey: 'inf', title: 'Работа', taskIds: array(), timeLimit: 0, attemptsAllowed: 0, passScore: 0.0,
			scoringPolicy: ScoringPolicy::Highest, status: 'publish', kind: $kind, taskPoints: array(), scoreMap: array(),
		) );
	}

	private function used( bool $used ): void {
		$this->usage->method( 'usageList' )->with( 'assessment', 7 )->willReturn( $used ? array( array( 'id' => 1, 'title' => 'Урок', 'type' => 'inf_lessons' ) ) : array() );
	}

	public function test_control_is_always_allowed(): void {
		$this->used( true );

		self::assertSame( AssessmentKind::Control, $this->guard->allowedKind( 7, AssessmentKind::Control ) );
	}

	public function test_station_is_refused_for_work_used_in_lesson_and_previous_kind_is_kept(): void {
		$this->current( AssessmentKind::Control );
		$this->used( true );

		self::assertSame( AssessmentKind::Control, $this->guard->allowedKind( 7, AssessmentKind::EgeComputer ) );
		self::assertSame( AssessmentKind::Control, $this->guard->allowedKind( 7, AssessmentKind::OgeComputer ) );
	}

	public function test_station_is_allowed_for_work_not_used_in_lessons(): void {
		$this->current( AssessmentKind::Control );
		$this->used( false );

		self::assertSame( AssessmentKind::EgeComputer, $this->guard->allowedKind( 7, AssessmentKind::EgeComputer ) );
	}

	public function test_new_work_without_saved_kind_counts_as_control(): void {
		$this->current( null );
		$this->used( true );

		self::assertSame( AssessmentKind::Control, $this->guard->allowedKind( 7, AssessmentKind::EgeComputer ) );
	}

	public function test_existing_station_stays_station_even_if_a_lesson_still_references_it(): void {
		// Dev-данные: станция, уже стоящая в уроке, не ломается — сохраняется как есть и может перейти между ЕГЭ/ОГЭ.
		$this->current( AssessmentKind::EgeComputer );
		$this->used( true );

		self::assertSame( AssessmentKind::OgeComputer, $this->guard->allowedKind( 7, AssessmentKind::OgeComputer ) );
	}
}