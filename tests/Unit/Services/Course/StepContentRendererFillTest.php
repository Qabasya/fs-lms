<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\Enums\Subject\TaskTemplate;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Managers\Wp\MediaManager;
use Inc\Managers\Wp\PostManager;
use Inc\Services\Course\StepContentRenderer;
use Inc\Services\Task\TaskCheckerRegistry;
use Inc\Services\Task\TaskMetaService;
use Inc\Services\Template\TemplateResolver;
use PHPUnit\Framework\TestCase;

/** Данные виджета «Пропуски в тексте»: режим ввода и режим плиток. */
class StepContentRendererFillTest extends TestCase {

	private StepContentRenderer $renderer;

	protected function setUp(): void {
		parent::setUp();
		$this->renderer = new StepContentRenderer(
			$this->createMock( PostManager::class ),
			$this->createMock( TemplateResolver::class ),
			$this->createMock( TaskCheckerRegistry::class ),
			$this->createMock( AssessmentManager::class ),
			new TaskMetaService(),
			$this->createMock( MediaManager::class ),
		);
	}

	public function test_input_mode_is_unchanged(): void {
		$data = $this->renderer->buildWidgetData(
			array( 'task_gap_text' => array( 'text' => 'Это [[кот]].' ) ),
			TaskTemplate::Fill,
			false
		);

		self::assertSame( 'fill', $data['type'] );
		self::assertArrayNotHasKey( 'answer_mode', $data );
		self::assertArrayNotHasKey( 'tiles', $data );
	}

	public function test_tiles_mode_sends_bank_with_extras(): void {
		$data = $this->renderer->buildWidgetData(
			array( 'task_gap_text' => array(
				'text'        => 'Это [[кот|кошка]] и [[пёс]].',
				'answer_mode' => 'tiles',
				'extra_tiles' => array( 'ёж' ),
			) ),
			TaskTemplate::Fill,
			false
		);

		self::assertSame( 'tiles', $data['answer_mode'] );
		$tiles = $data['tiles'];
		sort( $tiles );
		$expected = array( 'ёж', 'кот', 'пёс' );
		sort( $expected );
		self::assertSame( $expected, $tiles );
	}

	public function test_tiles_mode_does_not_leak_correct_answers_per_gap(): void {
		$data = $this->renderer->buildWidgetData(
			array( 'task_gap_text' => array( 'text' => 'Это [[кот|кошка]].', 'answer_mode' => 'tiles' ) ),
			TaskTemplate::Fill,
			false
		);

		self::assertStringNotContainsString( 'кошка', (string) wp_json_encode( $data ) );
		self::assertArrayNotHasKey( 'gaps', $data );
	}
}
