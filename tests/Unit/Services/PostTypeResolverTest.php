<?php

declare( strict_types=1 );

namespace Unit\Services;

use Inc\Services\Subject\PostTypeResolver;
use PHPUnit\Framework\TestCase;

class PostTypeResolverTest extends TestCase {

	public function test_builds_work_and_course_post_types(): void {
		self::assertSame( 'inf_works', PostTypeResolver::works( 'inf' ) );
		self::assertSame( 'inf_courses', PostTypeResolver::courses( 'inf' ) );
		self::assertSame( 'inf_lessons', PostTypeResolver::lessons( 'inf' ) );
		self::assertSame( 'inf_tasks', PostTypeResolver::tasks( 'inf' ) );
	}

	public function test_recognises_bank_post_types(): void {
		self::assertTrue( PostTypeResolver::isWorkPostType( 'inf_works' ) );
		self::assertTrue( PostTypeResolver::isCoursePostType( 'inf_courses' ) );
		self::assertFalse( PostTypeResolver::isWorkPostType( 'inf_lessons' ) );
		self::assertFalse( PostTypeResolver::isCoursePostType( 'inf_works' ) );
	}

	public function test_extracts_subject_from_work_and_course(): void {
		self::assertSame( 'inf', PostTypeResolver::subjectFromWorkPostType( 'inf_works' ) );
		self::assertSame( 'rus', PostTypeResolver::subjectFromCoursePostType( 'rus_courses' ) );
		self::assertSame( '', PostTypeResolver::subjectFromWorkPostType( 'inf_lessons' ) );
	}

	public function test_is_bank_post_type_covers_all_five_banks(): void {
		foreach ( array( 'inf_tasks', 'inf_works', 'inf_lessons', 'inf_courses', 'inf_articles' ) as $type ) {
			self::assertTrue( PostTypeResolver::isBankPostType( $type ), $type );
		}
		self::assertFalse( PostTypeResolver::isBankPostType( 'page' ) );
		self::assertFalse( PostTypeResolver::isBankPostType( 'inf_groups' ) );
	}
}
