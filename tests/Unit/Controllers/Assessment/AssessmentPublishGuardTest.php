<?php

declare( strict_types=1 );

namespace Unit\Controllers\Assessment;

use Inc\Controllers\Assessment\AssessmentMetaBoxController;
use Inc\DTO\Assessment\AssessmentDTO;
use Inc\DTO\Assessment\EgeCompletenessResult;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Assessment\ScoringPolicy;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Managers\Wp\MetaBoxManager;
use Inc\Managers\Wp\PostManager;
use Inc\MetaBoxes\Templates\AssessmentTemplate;
use Inc\Registrars\MetaBoxRegistrar;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Services\Assessment\EgeCompletenessChecker;
use Inc\Services\Task\TaskPublishGuard;
use PHPUnit\Framework\TestCase;

/**
 * T16.11 / T16.7: блок публикации незавершённой ЕГЭ-работы (D16.3.а).
 */
class AssessmentPublishGuardTest extends TestCase {

	private AssessmentManager $assessments;
	private EgeCompletenessChecker $completeness;
	private AssessmentMetaBoxController $controller;

	protected function setUp(): void {
		parent::setUp();
		unset( $_POST['fs_lms_meta'] );
		$this->assessments  = $this->createMock( AssessmentManager::class );
		$this->completeness = $this->createMock( EgeCompletenessChecker::class );

		$this->controller = new AssessmentMetaBoxController(
			$this->createMock( SubjectRepository::class ),
			$this->createMock( MetaBoxRegistrar::class ),
			$this->createMock( MetaBoxManager::class ),
			$this->createMock( AssessmentTemplate::class ),
			$this->createMock( PostManager::class ),
			$this->assessments,
			new TaskPublishGuard(),
			$this->completeness,
		);
	}

	private function assessment( AssessmentKind $kind ): AssessmentDTO {
		return new AssessmentDTO(
			id: 7, subjectKey: 'inf', title: 'ЕГЭ', taskIds: array( 1, 2 ),
			timeLimit: 0, attemptsAllowed: 0, passScore: 0.0,
			scoringPolicy: ScoringPolicy::Highest, status: 'draft',
			kind: $kind, taskPoints: array(), scoreMap: array(),
		);
	}

	/** @return array<string, mixed> */
	private function publishData(): array {
		return array( 'post_type' => 'inf_assessments', 'post_status' => 'publish', 'post_title' => 'ЕГЭ' );
	}

	public function test_incomplete_ege_publish_reverts_to_draft(): void {
		$this->assessments->method( 'get' )->willReturn( $this->assessment( AssessmentKind::Ege ) );
		$this->completeness->method( 'validate' )->willReturn(
			new EgeCompletenessResult( array( '3' ), array(), array(), 27, 26 )
		);

		$out = $this->controller->validateAssessmentTitle( $this->publishData(), array( 'ID' => 7 ) );

		$this->assertSame( 'draft', $out['post_status'] );
	}

	public function test_complete_ege_publish_allowed(): void {
		$this->assessments->method( 'get' )->willReturn( $this->assessment( AssessmentKind::Ege ) );
		$this->completeness->method( 'validate' )->willReturn(
			new EgeCompletenessResult( array(), array(), array(), 2, 2 )
		);

		$out = $this->controller->validateAssessmentTitle( $this->publishData(), array( 'ID' => 7 ) );

		$this->assertSame( 'publish', $out['post_status'] );
	}

	public function test_control_publish_not_gated_by_completeness(): void {
		$this->assessments->method( 'get' )->willReturn( $this->assessment( AssessmentKind::Control ) );
		$this->completeness->expects( $this->never() )->method( 'validate' );

		$out = $this->controller->validateAssessmentTitle( $this->publishData(), array( 'ID' => 7 ) );

		$this->assertSame( 'publish', $out['post_status'] );
	}
}
