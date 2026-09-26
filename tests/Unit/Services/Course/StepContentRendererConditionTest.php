<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\Enums\Subject\TaskTemplate;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Managers\Wp\PostManager;
use Inc\Services\Course\StepContentRenderer;
use Inc\Services\Task\TaskCheckerRegistry;
use Inc\Services\Task\TaskMetaService;
use Inc\Services\Template\TemplateResolver;
use PHPUnit\Framework\TestCase;
use Inc\Managers\Wp\MediaManager;

/**
 * `StepContentRenderer::buildConditionHtml()` для `TaskTemplate::AlternativeConditions`
 * (ОГЭ №13, 2026-08-18) — регресс: без этой ветки условие задания 13 было бы
 * пустым и на générique-странице попытки, и на станции (обе читают этот же
 * метод через `AttemptTaskViewBuilder::condition()`), т.к. поля называются
 * `task_condition_1`/`task_condition_2`, а не общий `task_condition`.
 */
class StepContentRendererConditionTest extends TestCase {

	private StepContentRenderer $renderer;

	private MediaManager $media;

	protected function setUp(): void {
		parent::setUp();
		$this->renderer = new StepContentRenderer(
			$this->createMock( PostManager::class ),
			$this->createMock( TemplateResolver::class ),
			$this->createMock( TaskCheckerRegistry::class ),
			$this->createMock( AssessmentManager::class ),
			new TaskMetaService(),
			$this->media = $this->createMock( MediaManager::class ),
		);
	}

	public function test_robo_gets_code_answer_widget(): void {
		self::assertSame(
			array( 'type' => 'code_answer' ),
			$this->renderer->buildWidgetData( array(), TaskTemplate::Robo, false )
		);
	}

	public function test_build_files_includes_task_materials_attachments(): void {
		$this->media->method( 'url' )->willReturnMap( array(
			array( 5, 'https://example.test/wp-content/uploads/scheme.png' ),
			array( 6, '' ),
		) );

		$files = $this->renderer->buildFiles( array(
			'file'           => 'https://example.test/data.txt',
			'task_materials' => array( 'attachment_ids' => array( 5, 6 ) ),
		) );

		self::assertSame(
			array(
				array( 'name' => 'data.txt', 'url' => 'https://example.test/data.txt' ),
				array( 'name' => 'scheme.png', 'url' => 'https://example.test/wp-content/uploads/scheme.png' ),
			),
			$files
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

	public function test_common_condition_collapses_only_when_asked(): void {
		$meta = array(
			'common_condition' => 'Типовая часть',
			'task_condition'   => 'Своя часть',
		);

		foreach ( array( TaskTemplate::Standard, TaskTemplate::Code, TaskTemplate::File, TaskTemplate::FileCode ) as $template ) {
			$collapsed = $this->renderer->buildConditionHtml( $meta, $template, true );
			$full      = $this->renderer->buildConditionHtml( $meta, $template );

			self::assertStringContainsString( '<details', $collapsed );
			self::assertStringNotContainsString( '<details', $full );
			self::assertStringContainsString( 'Типовая часть', $full );
			self::assertStringContainsString( 'Своя часть', $full );
		}
	}

	public function test_missing_fields_give_empty_strings_not_error(): void {
		$html = $this->renderer->buildConditionHtml( array(), TaskTemplate::AlternativeConditions );

		self::assertIsArray( $html );
		self::assertSame( '', $html['1'] );
		self::assertSame( '', $html['2'] );
	}
}
