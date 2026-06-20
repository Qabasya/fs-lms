<?php

declare( strict_types=1 );

namespace Unit\DTO\Course;

use Inc\DTO\Course\WorkDTO;
use Inc\Enums\Course\WorkType;
use PHPUnit\Framework\TestCase;
use WP_Post;

class WorkDTOTest extends TestCase {

	public function test_from_post_reads_meta_and_subject(): void {
		$post = new WP_Post( array(
			'ID'           => 10,
			'post_type'    => 'inf_works',
			'post_title'   => 'ДЗ: циклы',
			'post_content' => 'Сделать до пятницы',
			'post_author'  => 7,
			'post_status'  => 'publish',
		) );

		$dto = WorkDTO::fromPost( $post, array(
			'work_type' => 'homework',
			'item_ids'  => array( '3', 0, '5', '' ),
		) );

		self::assertSame( 'inf', $dto->subjectKey );
		self::assertSame( 'ДЗ: циклы', $dto->title );
		self::assertSame( WorkType::Homework, $dto->workType );
		self::assertSame( array( 3, 5 ), $dto->itemIds );
		self::assertSame( 'Сделать до пятницы', $dto->instructions );
		self::assertSame( 7, $dto->authorId );
		self::assertFalse( $dto->isEmpty() );
	}

	public function test_empty_work_has_no_tasks(): void {
		$dto = WorkDTO::fromArray( array( 'subject_key' => 'inf', 'title' => 'Пусто' ) );
		self::assertTrue( $dto->isEmpty() );
		self::assertSame( WorkType::Practice, $dto->workType );
	}

	public function test_array_round_trip_is_stable(): void {
		$dto   = WorkDTO::fromArray( array(
			'id'           => 1,
			'subject_key'  => 'inf',
			'title'        => 'СР',
			'work_type'    => 'independent',
			'item_ids'     => array( 2, 4 ),
			'instructions' => 'текст',
			'author_id'    => 3,
			'status'       => 'publish',
		) );
		$again = WorkDTO::fromArray( $dto->toArray() );

		self::assertEquals( $dto, $again );
		self::assertSame( 'independent', $dto->toArray()['work_type'] );
	}
}
