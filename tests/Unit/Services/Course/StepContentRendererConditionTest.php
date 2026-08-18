<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\Enums\Subject\TaskTemplate;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Managers\Wp\PostManager;
use Inc\Services\Course\StepContentRenderer;
use Inc\Services\Task\TaskCheckerRegistry;
use Inc\Services\Template\TemplateResolver;
use PHPUnit\Framework\TestCase;

/**
 * `StepContentRenderer::buildConditionHtml()` для `TaskTemplate::AlternativeConditions`
 * (ОГЭ №13, 2026-08-18) — регресс: без этой ветки условие задания 13 было бы
 * пустым и на générique-странице попытки, и на станции (обе читают этот же
 * метод через `AttemptTaskViewBuilder::condition()`), т.к. поля называются
 * `task_condition_1`/`task_condition_2`, а не общий `task_condition`.
 */
class StepContentRendererConditionTest extends TestCase {

	private StepContentRenderer $renderer;

	protected function setUp(): void {
		parent::setUp();
		$this->renderer = new StepContentRenderer(
			$this->createMock( PostManager::class ),
			$this->createMock( TemplateResolver::class ),
			$this->createMock( TaskCheckerRegistry::class ),
			$this->createMock( AssessmentManager::class ),
		);
	}

	public function test_returns_both_variant_conditions_as_array(): void {
		$html = $this->renderer->buildConditionHtml(
			array(
				'task_condition_1' => 'Условие варианта 1',
				'task_condition_2' => 'Условие варианта 2',
			),
			TaskTemplate::AlternativeConditions
		);

		self::assertIsArray( $html );
		self::assertArrayHasKey( '1', $html );
		self::assertArrayHasKey( '2', $html );
		self::assertStringContainsString( 'Условие варианта 1', $html['1'] );
		self::assertStringContainsString( 'Условие варианта 2', $html['2'] );
	}

	public function test_missing_fields_give_empty_strings_not_error(): void {
		$html = $this->renderer->buildConditionHtml( array(), TaskTemplate::AlternativeConditions );

		self::assertIsArray( $html );
		self::assertSame( '', $html['1'] );
		self::assertSame( '', $html['2'] );
	}
}
