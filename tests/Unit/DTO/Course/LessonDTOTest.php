<?php

declare( strict_types=1 );

namespace Unit\DTO\Course;

use Inc\DTO\Course\LessonDTO;
use PHPUnit\Framework\TestCase;
use WP_Post;

class LessonDTOTest extends TestCase {

	public function test_from_post_maps_work_ids_not_tasks(): void {
		$post = new WP_Post( array(
			'ID'           => 20,
			'post_type'    => 'inf_lessons',
			'post_title'   => 'Урок 5',
			'post_content' => '<p>Теория</p>',
		) );

		$dto = LessonDTO::fromPost( $post, array(
			'theory_article_id' => '8',
			'work_ids'          => array( '11', '', '12' ),
		) );

		self::assertSame( 'inf', $dto->subjectKey );
		self::assertSame( 'Урок 5', $dto->topic );
		self::assertSame( '<p>Теория</p>', $dto->theoryHtml );
		self::assertSame( 8, $dto->theoryArticleId );
		self::assertSame( array( 11, 12 ), $dto->workIds );
		self::assertFalse( $dto->isEmpty() );
	}

	public function test_lesson_without_works_is_empty(): void {
		$dto = LessonDTO::fromArray( array( 'subject_key' => 'inf', 'topic' => 'Просто занятие' ) );
		self::assertTrue( $dto->isEmpty() );
		self::assertArrayNotHasKey( 'task_ids', $dto->toArray() );
		self::assertArrayHasKey( 'work_ids', $dto->toArray() );
	}

	public function test_array_round_trip_is_stable(): void {
		$dto   = LessonDTO::fromArray( array(
			'id'                => 1,
			'subject_key'       => 'inf',
			'topic'             => 'Урок',
			'theory_html'       => 'x',
			'theory_article_id' => 0,
			'work_ids'          => array( 5, 6 ),
			'author_id'         => 2,
			'status'            => 'publish',
		) );

		self::assertEquals( $dto, LessonDTO::fromArray( $dto->toArray() ) );
	}
}
