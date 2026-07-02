<?php

declare( strict_types=1 );

namespace Unit\Services\Task;

use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Wp\PostManager;
use Inc\Services\Task\CorrectAnswerResolver;
use PHPUnit\Framework\TestCase;

class CorrectAnswerResolverTest extends TestCase {

	private function resolver( array $meta, string $tpl ): CorrectAnswerResolver {
		$posts = $this->createMock( PostManager::class );
		$posts->method( 'get' )->willReturn( new \WP_Post( array( 'ID' => 1 ) ) );
		$posts->method( 'getMeta' )->willReturnCallback(
			static fn( int $id, string $key ) => PostMetaName::Meta->value === $key
				? $meta
				: ( PostMetaName::TemplateType->value === $key ? $tpl : null )
		);
		return new CorrectAnswerResolver( $posts );
	}

	public function test_standard_returns_task_answer(): void {
		self::assertSame( '4', $this->resolver( array( 'task_answer' => '4' ), 'standard_task' )->resolve( 1 ) );
	}

	public function test_choice_joins_correct_option_texts(): void {
		$meta = array( 'task_options' => array( 'options' => array(
			array( 'id' => '1', 'text' => '2', 'correct' => true ),
			array( 'id' => '2', 'text' => '4', 'correct' => false ),
			array( 'id' => '3', 'text' => '3', 'correct' => true ),
		) ) );
		self::assertSame( '2, 3', $this->resolver( $meta, 'choice_task' )->resolve( 1 ) );
	}

	public function test_matching_renders_pairs(): void {
		$meta = array( 'task_pairs' => array( 'pairs' => array(
			array( 'left' => 'A', 'right' => '1' ),
			array( 'left' => 'B', 'right' => '2' ),
		) ) );
		self::assertSame( 'A → 1; B → 2', $this->resolver( $meta, 'matching_task' )->resolve( 1 ) );
	}

	public function test_ordering_numbers_items(): void {
		$meta = array( 'task_order_items' => array( 'items' => array( 'один', 'два' ) ) );
		self::assertSame( '1. один   2. два', $this->resolver( $meta, 'ordering_task' )->resolve( 1 ) );
	}

	public function test_fill_first_synonym_per_gap(): void {
		$meta = array( 'task_gap_text' => array( 'text' => 'Столица — [[Москва|Moscow]], река — [[Волга]].' ) );
		self::assertSame( '[1] Москва   [2] Волга', $this->resolver( $meta, 'fill_task' )->resolve( 1 ) );
	}

	public function test_manual_template_returns_null(): void {
		self::assertNull( $this->resolver( array( 'x' => 'y' ), 'code_task' )->resolve( 1 ) );
	}

	public function test_empty_answer_returns_null(): void {
		self::assertNull( $this->resolver( array( 'task_answer' => '' ), 'standard_task' )->resolve( 1 ) );
	}

	public function test_missing_post_returns_null(): void {
		$posts = $this->createMock( PostManager::class );
		$posts->method( 'get' )->willReturn( null );
		self::assertNull( ( new CorrectAnswerResolver( $posts ) )->resolve( 1 ) );
	}
}
