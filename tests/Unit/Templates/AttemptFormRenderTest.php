<?php

declare( strict_types=1 );

namespace Unit\Templates;

use Inc\DTO\Assessment\AssessmentDTO;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Assessment\ScoringPolicy;
use PHPUnit\Framework\TestCase;

/**
 * T16.11c: обычный Ege рендерится станцией-навигатором (боковое меню номеров),
 * Control — одностраничным списком. Общий партиал задания в обоих режимах.
 */
class AttemptFormRenderTest extends TestCase {

	private const PARTIALS = __DIR__ . '/../../../templates/frontend/assessment/partials';

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_posts();
		fs_test_seed_post( array( 'ID' => 10, 'post_type' => 'inf_assessments_task', 'post_title' => 'Задача 1' ) );
		fs_test_seed_post( array( 'ID' => 20, 'post_type' => 'inf_assessments_task', 'post_title' => 'Задача 2' ) );
	}

	private function assessment( AssessmentKind $kind ): AssessmentDTO {
		return new AssessmentDTO(
			id: 1, subjectKey: 'inf', title: 'Работа', taskIds: array( 10, 20 ),
			timeLimit: 0, attemptsAllowed: 0, passScore: 0.0,
			scoringPolicy: ScoringPolicy::Highest, status: 'publish',
			kind: $kind, taskPoints: array(), scoreMap: array(),
		);
	}

	/** @return array<int, array{template: string, materials: array, condition: string, taskNumber: int}> */
	private function taskViews(): array {
		return array(
			10 => array( 'template' => 'standard_task', 'materials' => array(), 'condition' => 'Условие 1', 'taskNumber' => 1 ),
			20 => array( 'template' => 'standard_task', 'materials' => array(), 'condition' => 'Условие 2', 'taskNumber' => 2 ),
		);
	}

	private function renderPartial( string $file, AssessmentKind $kind ): string {
		$assessment = $this->assessment( $kind );
		$taskViews  = $this->taskViews();
		ob_start();
		require self::PARTIALS . '/' . $file;
		return (string) ob_get_clean();
	}

	public function test_ege_renders_navigator_with_number_menu(): void {
		$html = $this->renderPartial( 'attempt-form-nav.php', AssessmentKind::Ege );

		$this->assertStringContainsString( 'fs-ege-nav', $html );
		$this->assertStringContainsString( 'fs-ege-nav__menu', $html );
		// Две кнопки-номера + два блока задания.
		$this->assertSame( 2, substr_count( $html, 'fs-ege-nav__num' ) );
		$this->assertSame( 2, substr_count( $html, 'fs-attempt-question"' ) );
		$this->assertStringContainsString( 'data-task-id="10"', $html );
		$this->assertStringContainsString( 'data-task-id="20"', $html );
	}

	public function test_control_renders_single_page_list(): void {
		$html = $this->renderPartial( 'attempt-form-list.php', AssessmentKind::Control );

		// Список — без навигатора, но с теми же блоками задания.
		$this->assertStringNotContainsString( 'fs-ege-nav', $html );
		$this->assertSame( 2, substr_count( $html, 'fs-attempt-question"' ) );
		$this->assertStringContainsString( 'fs-submit-attempt-btn', $html );
	}
}
