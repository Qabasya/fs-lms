<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;

use Inc\DTO\Assessment\AssessmentDTO;
use Inc\DTO\Exam\ExamFormatDTO;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Assessment\ScoringPolicy;
use Inc\Enums\Exam\ExamDirection;
use Inc\Enums\Log\ErrorCode;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Services\Exam\ExamFormatRegistry;
use Inc\Services\Exam\ExamVariantPolicy;
use Inc\Shared\CodedException;
use PHPUnit\Framework\TestCase;

class ExamVariantPolicyTest extends TestCase {

	private AssessmentManager $assessments;
	private ExamFormatRegistry $formats;
	private ExamVariantPolicy $policy;

	protected function setUp(): void {
		parent::setUp();
		$this->assessments  = $this->createMock( AssessmentManager::class );
		$this->formats      = $this->createMock( ExamFormatRegistry::class );
		$this->policy       = new ExamVariantPolicy( $this->assessments, $this->formats );
	}

	/** @param int[] $taskIds */
	private function assessment( string $subject = 'inf_ege', AssessmentKind $kind = AssessmentKind::EgeComputer, string $status = 'publish', array $taskIds = array( 1, 2, 3 ) ): AssessmentDTO {
		return new AssessmentDTO(
			id: 1, subjectKey: $subject, title: 'Вариант 1', taskIds: $taskIds,
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

	public function test_variant_of_same_subject_and_station_kind_passes(): void {
		$this->assessments->method( 'get' )->with( 1 )->willReturn( $this->assessment() );
		$this->formats->method( 'for' )->with( AssessmentKind::EgeComputer )->willReturn( $this->format() );

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

	public function test_variant_with_repeated_task_numbers_passes(): void {
		// Несколько заданий одного номера (например, два №14) — не причина отказа.
		$this->assessments->method( 'get' )->willReturn( $this->assessment( 'inf_ege', AssessmentKind::EgeComputer, 'publish', array( 1, 2 ) ) );
		$this->formats->method( 'for' )->willReturn( $this->format() );

		self::assertNull( $this->policy->check( 1, 'inf_ege' ) );
	}

	public function test_variant_without_tasks_is_rejected(): void {
		$this->assessments->method( 'get' )->willReturn( $this->assessment( 'inf_ege', AssessmentKind::EgeComputer, 'publish', array() ) );
		$this->formats->method( 'for' )->willReturn( $this->format() );

		self::assertSame( 'В варианте нет заданий.', $this->policy->check( 1, 'inf_ege' ) );
	}

	public function test_assert_throws_coded_exception_with_exam_state(): void {
		$this->assessments->method( 'get' )->willReturn( null );

		try {
			$this->policy->assert( 1, 'inf_ege' );
			self::fail( 'Ожидалось исключение.' );
		} catch ( CodedException $e ) {
			self::assertSame( ErrorCode::ExamState, $e->errorCode );
		}
	}
}
