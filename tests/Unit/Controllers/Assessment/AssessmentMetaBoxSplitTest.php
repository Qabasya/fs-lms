<?php

declare( strict_types=1 );

namespace Unit\Controllers\Assessment;

use Inc\Controllers\Assessment\AssessmentMetaBoxController;
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
 * Тип экзамена вынесен в свой метабокс (.docs/Tasks.md, «тип экзамена — отдельный
 * метабокс»): `kind` рендерится ТОЛЬКО в renderKindContent(), `score_map` — ТОЛЬКО
 * в renderScoreMapContent(), остальные три поля («Настройки контрольной») —
 * ТОЛЬКО в renderSettingsContent(). Состав полей по-прежнему один источник —
 * AssessmentTemplate::get_fields() — колбэки лишь фильтруют подмножество.
 */
class AssessmentMetaBoxSplitTest extends TestCase {

	private AssessmentMetaBoxController $controller;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_posts();

		$posts = $this->createMock( PostManager::class );
		$posts->method( 'taskMeta' )->willReturn( array() );

		$this->controller = new AssessmentMetaBoxController(
			$this->createMock( SubjectRepository::class ),
			$this->createMock( MetaBoxRegistrar::class ),
			$this->createMock( MetaBoxManager::class ),
			new AssessmentTemplate(),
			$posts,
			$this->createMock( AssessmentManager::class ),
			new TaskPublishGuard(),
			$this->createMock( EgeCompletenessChecker::class ),
		);
	}

	private function post(): \WP_Post {
		return fs_test_seed_post( array( 'ID' => 1, 'post_type' => 'inf_assessments' ) );
	}

	private function capture( callable $fn ): string {
		ob_start();
		$fn();
		return (string) ob_get_clean();
	}

	public function test_kind_metabox_renders_only_kind_field(): void {
		$html = $this->capture( fn() => $this->controller->renderKindContent( $this->post() ) );

		self::assertStringContainsString( 'for="kind"', $html );
		self::assertStringNotContainsString( 'for="time_limit_minutes"', $html );
		self::assertStringNotContainsString( 'for="max_attempts"', $html );
		self::assertStringNotContainsString( 'for="pass_score"', $html );
		self::assertStringNotContainsString( 'for="score_map"', $html );
		self::assertStringNotContainsString( 'intro_html', $html );
	}

	public function test_settings_metabox_renders_four_fields_but_not_kind_or_score_map(): void {
		$html = $this->capture( fn() => $this->controller->renderSettingsContent( $this->post() ) );

		self::assertStringContainsString( 'for="time_limit_minutes"', $html );
		self::assertStringContainsString( 'for="max_attempts"', $html );
		self::assertStringContainsString( 'for="pass_score"', $html );
		self::assertStringContainsString( 'intro_html', $html );
		self::assertStringNotContainsString( 'for="kind"', $html );
		self::assertStringNotContainsString( 'for="score_map"', $html );
	}

	public function test_score_map_metabox_renders_only_score_map_field(): void {
		$html = $this->capture( fn() => $this->controller->renderScoreMapContent( $this->post() ) );

		self::assertStringContainsString( 'for="score_map"', $html );
		self::assertStringNotContainsString( 'for="kind"', $html );
		self::assertStringNotContainsString( 'for="time_limit_minutes"', $html );
	}

	public function test_only_kind_metabox_outputs_the_nonce_field(): void {
		$kindHtml     = $this->capture( fn() => $this->controller->renderKindContent( $this->post() ) );
		$settingsHtml = $this->capture( fn() => $this->controller->renderSettingsContent( $this->post() ) );
		$scoreHtml    = $this->capture( fn() => $this->controller->renderScoreMapContent( $this->post() ) );

		self::assertStringContainsString( 'fs_lms_meta_nonce', $kindHtml );
		self::assertStringNotContainsString( 'fs_lms_meta_nonce', $settingsHtml );
		self::assertStringNotContainsString( 'fs_lms_meta_nonce', $scoreHtml );
	}
}
