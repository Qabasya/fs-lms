<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\Managers\Course\CourseManager;
use Inc\Services\Course\PublicCourseService;
use PHPUnit\Framework\TestCase;

/**
 * Блок «Курсы» сайдбара — заглушка программы, а не реальные курсы.
 */
class PublicCourseServiceSidebarTest extends TestCase {

	public function test_exam_subjects_get_program_stub_linking_to_subject_page(): void {
		$manager = $this->createMock( CourseManager::class );
		$manager->expects( self::never() )->method( 'getBankBySubject' );
		$service = new PublicCourseService( $manager );

		$ege = $service->getSidebarCourses( 'inf_ege', 'https://site.test/inf_ege/' );
		$oge = $service->getSidebarCourses( 'inf_oge', 'https://site.test/inf_oge/' );

		self::assertCount( 1, $ege );
		self::assertSame( 'Подготовка к ЕГЭ по информатике', $ege[0]->title );
		self::assertSame( 'https://site.test/inf_ege/', $ege[0]->url );
		self::assertSame( 72, $ege[0]->lessons );
		self::assertSame( 'Подготовка к ОГЭ по информатике', $oge[0]->title );
	}

	public function test_other_subject_or_missing_page_has_no_block(): void {
		$service = new PublicCourseService( $this->createStub( CourseManager::class ) );

		self::assertSame( array(), $service->getSidebarCourses( 'robo', 'https://site.test/robo/' ) );
		self::assertSame( array(), $service->getSidebarCourses( 'inf_ege', '' ) );
	}
}
