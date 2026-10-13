<?php

declare( strict_types=1 );

namespace Unit\Services\Subject;

use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Wp\PostManager;
use Inc\Services\Subject\ArticleContentService;
use Inc\Services\Task\TaskMetaService;
use PHPUnit\Framework\TestCase;

/**
 * Карточка задания в тексте статьи: показывает только собственное условие
 * задания. Типовое (общее) условие в карточку не попадает ни блоком, ни в
 * строку-анонс свёрнутой карточки.
 */
class ArticleContentServiceTest extends TestCase {

	private const TASK_ID = 501;

	private PostManager           $posts;
	private ArticleContentService $content;

	protected function setUp(): void {
		parent::setUp();

		if ( ! defined( 'FS_LMS_PATH' ) ) {
			define( 'FS_LMS_PATH', dirname( __DIR__, 4 ) . '/' );
		}

		$this->posts = $this->createMock( PostManager::class );
		$this->posts->method( 'renderContent' )->willReturnArgument( 0 );
		$this->posts->method( 'idFromUrl' )->willReturn( self::TASK_ID );
		$this->posts->method( 'get' )->willReturn(
			new \WP_Post( array(
				'ID'          => self::TASK_ID,
				'post_type'   => 'inf_tasks',
				'post_status' => 'publish',
				'post_title'  => 'Задание 5001',
			) )
		);

		$this->content = new ArticleContentService( $this->posts, new TaskMetaService() );
	}

	public function test_task_card_omits_common_condition(): void {
		$this->seedMeta( array(
			'common_condition' => '<p>Типовая часть</p>',
			'task_condition'   => '<p>Своя часть</p>',
		) );

		$html = $this->content->build( '<p><a href="http://example.com/inf/trainer/5001/">Задание</a></p>' )->html;

		self::assertStringContainsString( 'fs-article-task', $html );
		self::assertStringContainsString( 'Своя часть', $html );
		self::assertStringNotContainsString( 'Типовая часть', $html );
		self::assertStringNotContainsString( 'fs-common-cond', $html );
	}

	public function test_task_card_with_only_common_condition_has_empty_statement(): void {
		$this->seedMeta( array( 'common_condition' => '<p>Типовая часть</p>' ) );

		$html = $this->content->build( '<p><a href="http://example.com/inf/trainer/5001/">Задание</a></p>' )->html;

		self::assertStringContainsString( 'fs-article-task', $html );
		self::assertStringNotContainsString( 'Типовая часть', $html );
		self::assertStringNotContainsString( 'fs-article-task__peek', $html );
	}

	public function test_peek_preserves_safe_sub_sup_and_escapes_text_entities(): void {
		$this->seedMeta( array( 'task_condition' => '<p>Число 2<sup>10</sup> &amp; основание <sub>16</sub>.</p>' ) );

		$html = $this->content->build( '<p><a href="http://example.com/inf/trainer/5001/">Задание</a></p>' )->html;

		self::assertMatchesRegularExpression( '/<span class="fs-article-task__peek">Число 2<sup>10<\/sup> &(?:amp;)? основание <sub>16<\/sub>\.<\/span>/', $html );
	}

	public function test_peek_counts_inline_markup_as_part_of_a_word_and_separates_blocks(): void {
		$this->seedMeta( array( 'task_condition' => '<p>основание 2<sup>10</sup></p><p>дроби</p>' ) );

		$html = $this->content->build( '<p><a href="http://example.com/inf/trainer/5001/">Задание</a></p>' )->html;

		self::assertMatchesRegularExpression( '/<span class="fs-article-task__peek">основание 2<sup>10<\/sup> дроби<\/span>/', $html );
	}

	public function test_peek_truncates_after_28_words_without_leaving_an_unmatched_inline_tag(): void {
		$condition = implode( ' ', array_fill( 0, 28, 'слово' ) ) . ' <sup>лишнее</sup>';
		$this->seedMeta( array( 'task_condition' => '<p>' . $condition . '</p>' ) );

		$html = $this->content->build( '<p><a href="http://example.com/inf/trainer/5001/">Задание</a></p>' )->html;

		self::assertMatchesRegularExpression( '/<span class="fs-article-task__peek">(?:слово ?){28}…<\/span>/', $html );
		self::assertStringNotContainsString( '<sup></sup>', $html );
	}

	private function seedMeta( array $meta ): void {
		$this->posts
			->method( 'getMeta' )
			->with( self::TASK_ID, PostMetaName::Meta->value )
			->willReturn( $meta );
	}
}
