<?php

declare( strict_types=1 );

namespace Unit\Controllers\Assessment;

use Inc\Controllers\Assessment\AssessmentMetaBoxController;
use Inc\DTO\Assessment\AssessmentDTO;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Assessment\ScoringPolicy;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Managers\Wp\MetaBoxManager;
use Inc\Managers\Wp\PostManager;
use Inc\MetaBoxes\Templates\AssessmentTemplate;
use Inc\Registrars\MetaBoxRegistrar;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Services\Task\TaskPublishGuard;
use PHPUnit\Framework\TestCase;

/**
 * Публикация контрольной: станции КЕГЭ/КОГЭ публикуются с любым составом —
 * не все номера и задания не на своих позициях больше не блокируют.
 */
class AssessmentPublishGuardTest extends TestCase {

	private AssessmentManager $assessments;
	private AssessmentMetaBoxController $controller;

	protected function setUp(): void {
		parent::setUp();
		unset( $_POST['fs_lms_meta'] );
		$this->assessments = $this->createMock( AssessmentManager::class );

		$this->controller = new AssessmentMetaBoxController(
			$this->createMock( SubjectRepository::class ),
			$this->createMock( MetaBoxRegistrar::class ),
			$this->createMock( MetaBoxManager::class ),
			$this->createMock( AssessmentTemplate::class ),
			$this->createMock( PostManager::class ),
			$this->assessments,
			new TaskPublishGuard(),
			$this->createMock( \Inc\Services\Task\TaskBundleService::class ),
			$this->createMock( \Inc\Services\Assessment\AssessmentSlugService::class ),
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
	private function publishData( string $title = 'ЕГЭ' ): array {
		return array( 'post_type' => 'inf_assessments', 'post_status' => 'publish', 'post_title' => $title );
	}

	public function test_station_with_partial_composition_publishes(): void {
		$this->assessments->method( 'get' )->willReturn( $this->assessment( AssessmentKind::EgeComputer ) );

		$out = $this->controller->validateAssessmentTitle( $this->publishData(), array( 'ID' => 7 ) );

		$this->assertSame( 'publish', $out['post_status'] );
	}

	public function test_publish_without_title_reverts_to_draft(): void {
		$this->assessments->method( 'get' )->willReturn( $this->assessment( AssessmentKind::OgeComputer ) );

		$out = $this->controller->validateAssessmentTitle( $this->publishData( '' ), array( 'ID' => 7 ) );

		$this->assertSame( 'draft', $out['post_status'] );
	}
}
