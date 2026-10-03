<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;

use Inc\Services\Exam\ExamVariantPolicy;
use Inc\Services\Exam\ExamFormatRegistry;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Services\Assessment\EgeCompletenessChecker;
use Inc\DTO\Assessment\AssessmentDTO;
use Inc\DTO\Exam\ExamFormatDTO;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Exam\ExamDirection;
use Inc\Shared\CodedException;
use Inc\Enums\Log\ErrorCode;
use PHPUnit\Framework\TestCase;

class ExamVariantPolicyTest extends TestCase {

	private AssessmentManager $assessments;
	private ExamFormatRegistry $formats;
	private EgeCompletenessChecker $completeness;
	private ExamVariantPolicy $policy;

	protected function setUp(): void {
		$this->assessments = $this->createMock( AssessmentManager::class );
		$this->formats = $this->createMock( ExamFormatRegistry::class );
		$this->completeness = $this->createMock( EgeCompletenessChecker::class );
		$this->policy = new ExamVariantPolicy( $this->assessments, $this->formats, $this->completeness );
	}

	public function test_variant_of_same_subject_and_station_kind_passes(): void {
		$assessment = new AssessmentDTO(
			1, 'inf_ege', AssessmentKind::Kege, 'publish', array( 1, 2, 3 ),
			'Вариант 1', false, '', null, null, '', ''
		);

		$this->assessments->method( 'get' )->with( 1 )->willReturn( $assessment );

		$format = new ExamFormatDTO(
			AssessmentKind::Kege, ExamDirection::Ege, 27, 29, 100, null, 235,
			array(), array()
		);
		$this->formats->method( 'for' )->with( AssessmentKind::Kege )->willReturn( $format );

		$result = $this->createMock( \Inc\DTO\Assessment\EgeCompletenessResult::class );
		$result->method( 'isStrictlyComplete' )->willReturn( true );
		$this->completeness->method( 'validate' )->willReturn( $result );

		$reason = $this->policy->check( 1, 'inf_ege' );

		$this->assertNull( $reason );
	}

	public function test_variant_of_other_subject_is_rejected(): void {
		$assessment = new AssessmentDTO(
			1, 'inf_oge', AssessmentKind::Kege, 'publish', array( 1, 2, 3 ),
			'Вариант 1', false, '', null, null, '', ''
		);

		$this->assessments->method( 'get' )->with( 1 )->willReturn( $assessment );

		$reason = $this->policy->check( 1, 'inf_ege' );

		$this->assertStringContainsString( 'другому предмету', $reason );
	}

	public function test_control_kind_is_rejected(): void {
		$assessment = new AssessmentDTO(
			1, 'inf_ege', AssessmentKind::Control, 'publish', array( 1, 2, 3 ),
			'Контрольная', false, '', null, null, '', ''
		);

		$this->assessments->method( 'get' )->with( 1 )->willReturn( $assessment );

		$reason = $this->policy->check( 1, 'inf_ege' );

		$this->assertStringContainsString( 'только работа формата экзамена', $reason );
	}

	public function test_rejected_when_format_registry_empty(): void {
		$assessment = new AssessmentDTO(
			1, 'inf_ege', AssessmentKind::Kege, 'publish', array( 1, 2, 3 ),
			'Вариант 1', false, '', null, null, '', ''
		);

		$this->assessments->method( 'get' )->with( 1 )->willReturn( $assessment );
		$this->formats->method( 'for' )->with( AssessmentKind::Kege )->willReturn( null );

		$reason = $this->policy->check( 1, 'inf_ege' );

		$this->assertStringContainsString( 'модуль экзаменов выключен', $reason );
	}

	public function test_draft_variant_is_rejected(): void {
		$assessment = new AssessmentDTO(
			1, 'inf_ege', AssessmentKind::Kege, 'draft', array( 1, 2, 3 ),
			'Вариант 1', false, '', null, null, '', ''
		);

		$this->assessments->method( 'get' )->with( 1 )->willReturn( $assessment );

		$reason = $this->policy->check( 1, 'inf_ege' );

		$this->assertStringContainsString( 'не опубликован', $reason );
	}

	public function test_incomplete_variant_is_rejected(): void {
		$assessment = new AssessmentDTO(
			1, 'inf_ege', AssessmentKind::Kege, 'publish', array( 1, 2, 3 ),
			'Вариант 1', false, '', null, null, '', ''
		);

		$this->assessments->method( 'get' )->with( 1 )->willReturn( $assessment );

		$format = new ExamFormatDTO(
			AssessmentKind::Kege, ExamDirection::Ege, 27, 29, 100, null, 235,
			array(), array()
		);
		$this->formats->method( 'for' )->with( AssessmentKind::Kege )->willReturn( $format );

		$result = $this->createMock( \Inc\DTO\Assessment\EgeCompletenessResult::class );
		$result->method( 'isStrictlyComplete' )->willReturn( false );
		$result->method( 'summary' )->willReturn( 'тип 1: 0/5' );
		$this->completeness->method( 'validate' )->willReturn( $result );

		$reason = $this->policy->check( 1, 'inf_ege' );

		$this->assertStringContainsString( 'не укомплектован', $reason );
		$this->assertStringContainsString( 'тип 1: 0/5', $reason );
	}

	public function test_assert_throws_coded_exception_with_exam_conflict(): void {
		$this->assessments->method( 'get' )->with( 1 )->willReturn( null );

		$this->expectException( CodedException::class );
		$this->expectExceptionCode( ErrorCode::ExamConflict->value );

		$this->policy->assert( 1, 'inf_ege' );
	}
}
