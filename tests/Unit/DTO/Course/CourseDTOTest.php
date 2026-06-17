<?php

declare( strict_types=1 );

namespace Unit\DTO\Course;

use Inc\DTO\Course\CourseDTO;
use PHPUnit\Framework\TestCase;
use WP_Post;

class CourseDTOTest extends TestCase {

	public function test_from_post_maps_lesson_ids(): void {
		$post = new WP_Post( array(
			'ID'           => 30,
			'post_type'    => 'rus_courses',
			'post_title'   => 'ЕГЭ Русский 2026',
			'post_content' => 'Описание',
		) );

		$dto = CourseDTO::fromPost( $post, array( 'lesson_ids' => array( 1, 2, 3 ) ) );

		self::assertSame( 'rus', $dto->subjectKey );
		self::assertSame( 'ЕГЭ Русский 2026', $dto->title );
		self::assertSame( 'Описание', $dto->descriptionHtml );
		self::assertSame( array( 1, 2, 3 ), $dto->lessonIds );
		self::assertFalse( $dto->isEmpty() );
	}

	public function test_empty_course(): void {
		$dto = CourseDTO::fromArray( array( 'subject_key' => 'rus', 'title' => 'Черновик' ) );
		self::assertTrue( $dto->isEmpty() );
	}

	public function test_array_round_trip_is_stable(): void {
		$dto = CourseDTO::fromArray( array(
			'id'               => 1,
			'subject_key'      => 'rus',
			'title'            => 'Курс',
			'description_html' => 'd',
			'lesson_ids'       => array( 9, 8, 7 ),
			'author_id'        => 4,
			'status'           => 'draft',
		) );

		self::assertEquals( $dto, CourseDTO::fromArray( $dto->toArray() ) );
		self::assertSame( array( 9, 8, 7 ), $dto->toArray()['lesson_ids'] );
	}
}
