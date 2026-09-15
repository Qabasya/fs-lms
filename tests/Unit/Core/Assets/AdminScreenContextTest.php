<?php

declare( strict_types=1 );

// Минимальный WP_Screen: AdminScreenContext читает только base и post_type.
namespace {
	if ( ! class_exists( 'WP_Screen' ) ) {
		class WP_Screen {
			public string $base      = '';
			public string $post_type = '';
		}
	}
}

namespace Unit\Core\Assets {

	use Inc\Core\Assets\AdminScreenContext;
	use PHPUnit\Framework\TestCase;

	/**
	 * Что подключать на экране админки: медиатека и редактор — только там, где их открывают.
	 */
	class AdminScreenContextTest extends TestCase {

		private function ctx( string $base, string $postType = '', string $page = '' ): AdminScreenContext {
			$screen            = new \WP_Screen();
			$screen->base      = $base;
			$screen->post_type = $postType;

			return AdminScreenContext::from( $screen, $page );
		}

		public function test_list_screens_get_neither_media_nor_editor(): void {
			foreach ( array( 'inf_ege_lessons', 'inf_ege_courses', 'inf_ege_tasks', 'inf_ege_articles' ) as $postType ) {
				$ctx = $this->ctx( 'edit', $postType );

				$this->assertTrue( $ctx->needsAssets(), $postType );
				$this->assertFalse( $ctx->needsMedia(), $postType );
				$this->assertFalse( $ctx->needsEditor(), $postType );
				$this->assertFalse( $ctx->needsTaskEditor(), $postType );
			}
		}

		public function test_edit_screens_of_content_get_media(): void {
			foreach ( array( 'inf_ege_tasks', 'inf_ege_articles', 'inf_ege_works', 'inf_ege_lessons' ) as $postType ) {
				$this->assertTrue( $this->ctx( 'post', $postType )->needsMedia(), $postType );
			}
		}

		public function test_step_editor_screens_get_editor(): void {
			$this->assertTrue( $this->ctx( 'post', 'inf_ege_lessons' )->needsEditor() );
			$this->assertTrue( $this->ctx( 'post', 'inf_ege_courses' )->needsEditor() );
			$this->assertFalse( $this->ctx( 'post', 'inf_ege_tasks' )->needsEditor() );

			// Конструктор курса — страница плагина без CPT у экрана.
			$builder = $this->ctx( 'admin_page_fs_lms_course_builder', '', 'fs_lms_course_builder' );
			$this->assertTrue( $builder->needsEditor() );
			$this->assertTrue( $builder->needsMedia() );
		}

		public function test_settings_page_gets_media_for_logo_only(): void {
			$settings = $this->ctx( 'fs-lms_page_fs_lms_settings', '', 'fs_lms_settings' );

			$this->assertTrue( $settings->needsMedia() );
			$this->assertFalse( $settings->needsEditor() );
			$this->assertFalse( $this->ctx( 'fs-lms_page_fs_lms_userlist', '', 'fs_lms_userlist' )->needsMedia() );
		}
	}
}
