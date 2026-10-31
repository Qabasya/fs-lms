<?php

declare( strict_types=1 );

namespace Unit\Templates;

use PHPUnit\Framework\TestCase;

class WorkPointsTemplateTest extends TestCase {

	private function render( array $overrides = array() ): string {
		$render = $overrides + array(
			'work_found' => true, 'ref' => 55, 'title' => 'Работа',
			'work_type' => 'independent', 'work_type_label' => 'Самостоятельная работа',
			'task_count' => 2, 'total_points' => 7,
			'tasks' => array(
				array( 'task_id' => 71, 'title' => 'Первая задача', 'max_score' => 2, 'auto_grade' => true, 'template' => 'text_task', 'widget_data' => array() ),
				array( 'task_id' => 72, 'title' => 'Вторая задача', 'max_score' => 5, 'auto_grade' => false, 'template' => 'text_task', 'widget_data' => array() ),
			),
		);
		$is_preview = false;
		$is_teacher_view = false;
		$edit_url = '';
		ob_start();
		include __DIR__ . '/../../../templates/frontend/lesson-player/partials/step-work.php';
		return (string) ob_get_clean();
	}

	public function test_task_header_costs_are_visible_before_any_answer_without_solutions(): void {
		$html = $this->render();
		self::assertStringContainsString( 'data-max-score="2"', $html );
		self::assertStringContainsString( 'data-task-points>2 балла</span>', $html );
		self::assertStringContainsString( 'data-task-points>5 баллов</span>', $html );
		self::assertStringContainsString( 'баллов: 7', $html );
		self::assertStringNotContainsString( 'Показать решение', $html );
	}

	public function test_reload_transfers_only_previously_granted_solutions_and_persisted_scoring(): void {
		$html = $this->render( array(
			'submission' => array( 'score' => 2.5, 'max_score' => 7 ),
			'task_results' => array( 72 => array( 'status' => 'graded', 'score' => 2.5, 'max_score' => 5 ) ),
			'solutions' => array( 71 => '<p>Разрешённое решение</p>' ),
			'past_due' => true,
		) );
		preg_match( "/data-state='([^']*)'/", $html, $match );
		$state = json_decode( $match[1], true );
		self::assertSame( 2.5, $state['submission']['score'] );
		self::assertSame( 7, $state['submission']['max_score'] );
		self::assertSame( 2.5, $state['task_results'][72]['score'] );
		self::assertArrayNotHasKey( 72, $state['solutions'] );
		self::assertSame( '<p>Разрешённое решение</p>', $state['solutions'][71] );
		self::assertTrue( $state['past_due'] );
	}
}
