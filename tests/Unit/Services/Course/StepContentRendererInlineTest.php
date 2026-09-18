<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\DTO\Course\StepDTO;
use Inc\Enums\Course\StepType;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Managers\Wp\PostManager;
use Inc\Services\Course\StepContentRenderer;
use Inc\Services\Task\TaskCheckerRegistry;
use Inc\Services\Template\TemplateResolver;
use PHPUnit\Framework\TestCase;

/**
 * Текст шага «Лекция» обязан проходить конвейер `the_content`: на нём висят
 * `wpautop`, шорткоды, oEmbed и сторонние плагины контента (QuickLaTeX) —
 * без него формулы на шаге оставались набором долларов, хотя в статье и
 * в задании (`TaskMetaService`) работали.
 */
class StepContentRendererInlineTest extends TestCase {

	private function renderer( PostManager $posts ): StepContentRenderer {
		return new StepContentRenderer(
			$posts,
			$this->createMock( TemplateResolver::class ),
			$this->createMock( TaskCheckerRegistry::class ),
			$this->createMock( AssessmentManager::class ),
		);
	}

	public function test_lecture_content_goes_through_the_content_pipeline(): void {
		$posts = $this->createMock( PostManager::class );
		$posts->expects( $this->once() )
			->method( 'renderContent' )
			->with( 'Формула $$x^2$$' )
			->willReturn( '<p>Формула <img src="formula.png" alt="x^2" /></p>' );

		$data = $this->renderer( $posts )->renderInlineData(
			new StepDTO( 's1', StepType::Text, array( 'content' => 'Формула $$x^2$$' ) )
		);

		self::assertSame( '<p>Формула <img src="formula.png" alt="x^2" /></p>', $data['content'] );
	}

	public function test_missing_lecture_content_is_rendered_as_empty_string(): void {
		$posts = $this->createMock( PostManager::class );
		$posts->expects( $this->once() )->method( 'renderContent' )->with( '' )->willReturn( '' );

		$data = $this->renderer( $posts )->renderInlineData( new StepDTO( 's1', StepType::Text, array() ) );

		self::assertSame( '', $data['content'] );
	}

	public function test_video_description_stays_plain_text(): void {
		// Описание видео — plain-text поле: шаблон печатает его esc_html внутри <p>,
		// прогон через the_content вложил бы абзац в абзац.
		$posts = $this->createMock( PostManager::class );
		$posts->expects( $this->never() )->method( 'renderContent' );

		$data = $this->renderer( $posts )->renderInlineData(
			new StepDTO( 's2', StepType::Video, array( 'url' => 'https://example.com/v.mp4', 'description' => 'Конспект' ) )
		);

		self::assertSame( 'Конспект', $data['description'] );
	}
}
