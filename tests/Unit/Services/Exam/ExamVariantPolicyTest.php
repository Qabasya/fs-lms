<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;

use Inc\DTO\Assessment\AssessmentDTO;
use Inc\DTO\Assessment\EgeCompletenessResult;
use Inc\DTO\Exam\ExamFormatDTO;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Assessment\ScoringPolicy;
use Inc\Enums\Exam\ExamDirection;
use Inc\Enums\Log\ErrorCode;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Services\Assessment\EgeCompletenessChecker;
use Inc\Services\Exam\ExamFormatRegistry;
use Inc\Services\Exam\ExamVariantPolicy;
use Inc\Shared\CodedException;
use PHPUnit\Framework\TestCase;

class ExamVariantPolicyTest extends TestCase {

	private AssessmentManager $assessments;
	private ExamFormatRegistry $formats;
	private EgeCompletenessChecker $completeness;
	private ExamVariantPolicy $policy;

	protected function setUp(): void {
		parent::setUp();
		$this->assessments  = $this->createMock( AssessmentManager::class );
		$this->formats      = $this->createMock( ExamFormatRegistry::class );
		$this->completeness = $this->createMock( EgeCompletenessChecker::class );
		$this->policy       = new ExamVariantPolicy( $this->assessments, $this->formats, $this->completeness );
	}

	private function assessment( string $subject = 'inf_ege', AssessmentKind $kind = AssessmentKind::EgeComputer, string $status = 'publish' ): AssessmentDTO {
		return new AssessmentDTO(
			id: 1, subjectKey: $subject, title: 'Вариант 1', taskIds: array( 1, 2, 3 ),
			timeLimit: 235, attemptsAllowed: 1, passScore: 0.0,
			scoringPolicy: ScoringPolicy::Highest, status: $status,
			kind: $kind, taskPoints: array(), scoreMap: array(),
		);
	}

	private function format( AssessmentKind $kind = AssessmentKind::EgeComputer ): ExamFormatDTO {
		return new ExamFormatDTO(
			kind: $kind, direction: ExamDirection::Ege, unitCount: 27, primaryMax: 29,
			secondaryMax: 100, gradeMax: 0, durationMinutes: 235, scale: array(), unitMaxScores: array(),
		);
	}

	private function completenessResult( array $missing = array() ): EgeCompletenessResult {
		return new EgeCompletenessResult(
			missing: $missing, duplicated: array(), orphans: array(), expectedCount: 27, actualCount: 27 - count( $missing ),
		);
	}

	public function test_variant_of_same_subject_and_station_kind_passes(): void {
		$this->assessments->method( 'get' )->with( 1 )->willReturn( $this->assessment() );
		$this->formats->method( 'for' )->with( AssessmentKind::EgeComputer )->willReturn( $this->format() );
		$this->completeness->method( 'validate' )->willReturn( $this->completenessResult() );

		self::assertNull( $this->policy->check( 1, 'inf_ege' ) );
	}

	public function test_missing_variant_is_rejected(): void {
		$this->assessments->method( 'get' )->willReturn( null );

		self::assertSame( 'Вариант не найден.', $this->policy->check( 1, 'inf_ege' ) );
	}

	public function test_variant_of_other_subject_is_rejected(): void {
		$this->assessments->method( 'get' )->willReturn( $this->assessment( 'inf_oge' ) );

		self::assertStringContainsString( 'другому предмету', (string) $this->policy->check( 1, 'inf_ege' ) );
	}

	public function test_control_kind_is_rejected(): void {
		$this->assessments->method( 'get' )->willReturn( $this->assessment( 'inf_ege', AssessmentKind::Control ) );

		self::assertStringContainsString( 'только работа формата экзамена', (string) $this->policy->check( 1, 'inf_ege' ) );
	}

	public function test_rejected_when_format_registry_empty(): void {
		$this->assessments->method( 'get' )->willReturn( $this->assessment() );
		$this->formats->method( 'for' )->willReturn( null );

		self::assertStringContainsString( 'модуль экзаменов выключен', (string) $this->policy->check( 1, 'inf_ege' ) );
	}

	public function test_draft_variant_is_rejected(): void {
		$this->assessments->method( 'get' )->willReturn( $this->assessment( 'inf_ege', AssessmentKind::EgeComputer, 'draft' ) );
		$this->formats->method( 'for' )->willReturn( $this->format() );

		self::assertStringContainsString( 'не опубликован', (string) $this->policy->check( 1, 'inf_ege' ) );
	}

	public function test_incomplete_variant_is_rejected_with_reason(): void {
		$this->assessments->method( 'get' )->willReturn( $this->assessment() );
		$this->formats->method( 'for' )->willReturn( $this->format() );
		$this->completeness->method( 'validate' )->willReturn( $this->completenessResult( array( '5', '6' ) ) );

		$reason = (string) $this->policy->check( 1, 'inf_ege' );

		self::assertStringContainsString( 'не укомплектован', $reason );
	}

	public function test_completeness_is_checked_for_the_assessment_and_subject(): void {
		$assessment = $this->assessment();
		$this->assessments->method( 'get' )->willReturn( $assessment );
		$this->formats->method( 'for' )->willReturn( $this->format() );
		$this->completeness->expects( self::once() )->method( 'validate' )->with( $assessment, 'inf_ege' )->willReturn( $this->completenessResult() );

		$this->policy->check( 1, 'inf_ege' );
	}

	public function test_assert_throws_coded_exception_with_exam_conflict(): void {
		$this->assessments->method( 'get' )->willReturn( null );

		try {
			$this->policy->assert( 1, 'inf_ege' );
			self::fail( 'Ожидалось исключение.' );
		} catch ( CodedException $e ) {
			self::assertSame( ErrorCode::ExamConflict, $e->errorCode );
		}
	}
}
