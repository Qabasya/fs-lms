<?php

declare( strict_types=1 );

namespace Unit\DTO\Course;

use Inc\DTO\Course\CourseDTO;
use Inc\DTO\Course\ModuleDTO;
use PHPUnit\Framework\TestCase;
use WP_Post;

class CourseDTOTest extends TestCase {

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function modulesMeta(): array {
		return array(
			array( 'id' => 'm1', 'title' => 'Задание 7', 'lesson_ids' => array( 1, 2 ) ),
			array( 'id' => 'm2', 'title' => 'Задание 8', 'lesson_ids' => array( 3 ) ),
		);
	}

	public function test_from_post_reads_modules_and_title(): void {
		$post = new WP_Post( array(
			'ID'           => 30,
			'post_type'    => 'rus_courses',
			'post_title'   => 'ЕГЭ Русский 2026',
			'post_content' => 'Описание',
		) );

		$dto = CourseDTO::fromPost( $post, array( 'modules' => $this->modulesMeta() ) );

		self::assertSame( 'rus', $dto->subjectKey );
		self::assertSame( 'ЕГЭ Русский 2026', $dto->title );
		self::assertSame( 'Описание', $dto->descriptionHtml );
		self::assertCount( 2, $dto->modules );
		self::assertContainsOnlyInstancesOf( ModuleDTO::class, $dto->modules );
		self::assertFalse( $dto->isEmpty() );
	}

	public function test_lesson_ids_flatten_modules_in_order(): void {
		$dto = CourseDTO::fromArray( array( 'subject_key' => 'rus', 'title' => 'T', 'modules' => $this->modulesMeta() ) );

		self::assertSame( array( 1, 2, 3 ), $dto->lessonIds() );
	}

	public function test_empty_course_without_lessons(): void {
		$dto = CourseDTO::fromArray( array( 'subject_key' => 'rus', 'title' => 'Черновик' ) );
		self::assertTrue( $dto->isEmpty() );
		self::assertSame( array(), $dto->lessonIds() );

		$withEmptyModule = CourseDTO::fromArray( array(
			'subject_key' => 'rus',
			'title'       => 'Каркас',
			'modules'     => array( array( 'id' => 'm1', 'title' => 'Пустой', 'lesson_ids' => array() ) ),
		) );
		self::assertTrue( $withEmptyModule->isEmpty() );
		self::assertCount( 1, $withEmptyModule->modules );
	}

	public function test_to_array_serializes_modules_and_drops_flat_lesson_ids(): void {
		$dto = CourseDTO::fromArray( array( 'subject_key' => 'rus', 'title' => 'T', 'modules' => $this->modulesMeta() ) );
		$arr = $dto->toArray();

		self::assertArrayHasKey( 'modules', $arr );
		self::assertArrayNotHasKey( 'lesson_ids', $arr );
		self::assertSame( array( 1, 2 ), $arr['modules'][0]['lesson_ids'] );
	}

	public function test_array_round_trip_is_stable(): void {
		$dto = CourseDTO::fromArray( array(
			'id'               => 1,
			'subject_key'      => 'rus',
			'title'            => 'Курс',
			'description_html' => 'd',
			'modules'          => $this->modulesMeta(),
			'author_id'        => 4,
			'status'           => 'draft',
		) );

		self::assertEquals( $dto, CourseDTO::fromArray( $dto->toArray() ) );
	}
}
