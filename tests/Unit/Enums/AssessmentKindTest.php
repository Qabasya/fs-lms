<?php

declare( strict_types=1 );

namespace Unit\Enums;

use Inc\Enums\Assessment\AssessmentKind;
use PHPUnit\Framework\TestCase;

/**
 * T16.1: предикаты режима оценивания AssessmentKind.
 */
class AssessmentKindTest extends TestCase {

	public function test_only_control_uses_binary_scoring(): void {
		$this->assertTrue( AssessmentKind::Control->binaryScoring() );
		$this->assertFalse( AssessmentKind::Ege->binaryScoring() );
		$this->assertFalse( AssessmentKind::EgeComputer->binaryScoring() );
	}

	public function test_binary_scoring_is_inverse_of_weighted_score(): void {
		foreach ( AssessmentKind::cases() as $kind ) {
			$this->assertSame(
				! $kind->usesWeightedScore(),
				$kind->binaryScoring(),
				"binaryScoring должен быть противоположен usesWeightedScore для {$kind->value}"
			);
		}
	}

	public function test_ege_kinds_need_completeness_check(): void {
		$this->assertTrue( AssessmentKind::Ege->needsCompletenessCheck() );
		$this->assertTrue( AssessmentKind::EgeComputer->needsCompletenessCheck() );
		$this->assertFalse( AssessmentKind::Control->needsCompletenessCheck() );
	}
}
