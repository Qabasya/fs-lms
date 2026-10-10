<?php

declare( strict_types=1 );

namespace Tests\Unit\Migrations;

use Inc\Enums\Wp\PageRoutes;
use PHPUnit\Framework\TestCase;

class ExamPageDefinitionsTest extends TestCase {

	public function test_definitions_preserve_all_existing_routes_titles_and_shortcodes(): void {
		$definitions = array_map( static fn ( PageRoutes $route ): array => array( $route->value, $route->guestExamTitle(), $route->guestExamShortCode()->tag() ), PageRoutes::guestExamPages() );
		self::assertSame( array(
			array( 'exam-signup', 'Запись на экзамен', '[fs_lms_exam_signup]' ),
			array( 'exam-entry', 'Вход на экзамен', '[fs_lms_exam_entry]' ),
			array( 'exam-result', 'Результат экзамена', '[fs_lms_exam_result]' ),
			array( 'exam-report', 'Отчёт по экзамену', '[fs_lms_exam_report]' ),
		), $definitions );
	}
}
