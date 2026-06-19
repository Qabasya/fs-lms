<?php

declare( strict_types=1 );

namespace Unit\DTO\Course;

use Inc\DTO\Course\LessonDTO;
use Inc\DTO\Course\StepDTO;
use PHPUnit\Framework\TestCase;
use WP_Post;

class LessonDTOTest extends TestCase {

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function stepsMeta(): array {
		return array(
			array( 'key' => 's1', 'type' => 'text', 'payload' => array( 'content' => 'Теория' ) ),
			array( 'key' => 's2', 'type' => 'work', 'payload' => array( 'ref' => 11 ) ),
			array( 'key' => 's3', 'type' => 'work', 'payload' => array( 'ref' => 12 ) ),
			array( 'key' => 's4', 'type' => 'assessment', 'payload' => array( 'ref' => 30 ) ),
			array( 'key' => 's5', 'type' => 'task', 'payload' => array( 'ref' => 40, 'source' => 'bank' ) ),
			array( 'key' => 's6', 'type' => 'material', 'payload' => array( 'article_id' => 8 ) ),
		);
	}

	public function test_from_post_reads_steps_and_topic(): void {
		$post = new WP_Post( array(
			'ID'         => 20,
			'post_type'  => 'inf_lessons',
			'post_title' => 'Урок 5',
		) );

		$dto = LessonDTO::fromPost( $post, array( 'steps' => $this->stepsMeta() ) );

		self::assertSame( 'inf', $dto->subjectKey );
		self::assertSame( 'Урок 5', $dto->topic );
		self::assertCount( 6, $dto->steps );
		self::assertContainsOnlyInstancesOf( StepDTO::class, $dto->steps );
		self::assertFalse( $dto->isEmpty() );
	}

	public function test_derived_ref_accessors_extract_by_step_type(): void {
		$dto = LessonDTO::fromArray( array( 'subject_key' => 'inf', 'topic' => 'T', 'steps' => $this->stepsMeta() ) );

		self::assertSame( array( 11, 12 ), $dto->workIds() );
		self::assertSame( array( 30 ), $dto->assessmentIds() );
		self::assertSame( array( 40 ), $dto->taskIds() );
		self::assertSame( array( 8 ), $dto->articleIds() );
	}

	public function test_lesson_without_steps_is_empty(): void {
		$dto = LessonDTO::fromArray( array( 'subject_key' => 'inf', 'topic' => 'Просто занятие' ) );

		self::assertTrue( $dto->isEmpty() );
		self::assertSame( array(), $dto->workIds() );
	}

	public function test_to_array_serializes_steps_and_drops_legacy_keys(): void {
		$dto = LessonDTO::fromArray( array( 'subject_key' => 'inf', 'topic' => 'T', 'steps' => $this->stepsMeta() ) );
		$arr = $dto->toArray();

		self::assertArrayHasKey( 'steps', $arr );
		self::assertArrayNotHasKey( 'work_ids', $arr );
		self::assertArrayNotHasKey( 'theory_article_id', $arr );
		self::assertSame( 'work', $arr['steps'][1]['type'] );
	}

	public function test_array_round_trip_is_stable(): void {
		$dto = LessonDTO::fromArray( array(
			'id'          => 1,
			'subject_key' => 'inf',
			'topic'       => 'Урок',
			'steps'       => $this->stepsMeta(),
			'author_id'   => 2,
			'status'      => 'publish',
		) );

		self::assertEquals( $dto, LessonDTO::fromArray( $dto->toArray() ) );
	}
}
