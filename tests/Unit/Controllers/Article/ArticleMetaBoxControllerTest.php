<?php

declare( strict_types=1 );

namespace Unit\Controllers\Article;

use Inc\Controllers\Article\ArticleMetaBoxController;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Wp\PostManager;
use Inc\Registrars\MetaBoxRegistrar;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use PHPUnit\Framework\TestCase;

/**
 * Краткое описание статьи: сохраняется только с CPT статей, только при наличии
 * поля в форме и всегда обрезанным до предела длины.
 */
class ArticleMetaBoxControllerTest extends TestCase {

	private const FIELD = 'fs_lms_article_description';

	private PostManager              $posts;
	private ArticleMetaBoxController $controller;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();

		$this->posts = $this->createMock( PostManager::class );

		$this->controller = new ArticleMetaBoxController(
			$this->createMock( SubjectRepository::class ),
			$this->createMock( MetaBoxRegistrar::class ),
			$this->posts
		);
	}

	private function article( int $id = 21, string $type = 'inf_articles' ): \WP_Post {
		return new \WP_Post( array( 'ID' => $id, 'post_type' => $type ) );
	}

	public function test_description_is_saved_for_article(): void {
		$this->posts->method( 'get' )->willReturn( $this->article() );

		$_POST = array(
			self::FIELD           => '  Как решать задание 1  ',
			'fs_lms_meta_nonce'   => 'nonce',
		);

		$this->posts->expects( $this->once() )
			->method( 'updateMeta' )
			->with( 21, PostMetaName::ArticleDescription->value, 'Как решать задание 1' );

		$this->controller->handleArticleSave( 21 );
	}

	public function test_description_is_truncated_to_the_limit(): void {
		$this->posts->method( 'get' )->willReturn( $this->article() );

		// Кириллица: обрезка обязана считать символы, а не байты.
		$long = str_repeat( 'я', 200 );

		$_POST = array(
			self::FIELD         => $long,
			'fs_lms_meta_nonce' => 'nonce',
		);

		$this->posts->expects( $this->once() )
			->method( 'updateMeta' )
			->with(
				21,
				PostMetaName::ArticleDescription->value,
				$this->callback( static fn( string $value ): bool =>
					mb_strlen( $value, 'UTF-8' ) === ArticleMetaBoxController::MAX_LENGTH
				)
			);

		$this->controller->handleArticleSave( 21 );
	}

	public function test_missing_field_leaves_stored_description_untouched(): void {
		$this->posts->method( 'get' )->willReturn( $this->article() );

		// Быстрое/массовое редактирование метабоксов не шлёт — описание не должно стираться.
		$_POST = array( 'fs_lms_meta_nonce' => 'nonce' );

		$this->posts->expects( $this->never() )->method( 'updateMeta' );

		$this->controller->handleArticleSave( 21 );
	}

	public function test_empty_value_clears_description(): void {
		$this->posts->method( 'get' )->willReturn( $this->article() );

		$_POST = array(
			self::FIELD         => '',
			'fs_lms_meta_nonce' => 'nonce',
		);

		$this->posts->expects( $this->once() )
			->method( 'updateMeta' )
			->with( 21, PostMetaName::ArticleDescription->value, '' );

		$this->controller->handleArticleSave( 21 );
	}

	public function test_other_post_types_are_ignored(): void {
		$this->posts->method( 'get' )->willReturn( $this->article( 21, 'inf_tasks' ) );

		$_POST = array(
			self::FIELD         => 'Описание',
			'fs_lms_meta_nonce' => 'nonce',
		);

		$this->posts->expects( $this->never() )->method( 'updateMeta' );

		$this->controller->handleArticleSave( 21 );
	}

	public function test_save_without_valid_nonce_is_rejected(): void {
		$this->posts->method( 'get' )->willReturn( $this->article() );

		$_POST = array( self::FIELD => 'Описание' );

		$this->posts->expects( $this->never() )->method( 'updateMeta' );

		$this->controller->handleArticleSave( 21 );
	}
}
