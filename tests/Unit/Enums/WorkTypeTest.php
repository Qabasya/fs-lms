<?php

declare( strict_types=1 );

namespace Unit\Enums;

use Inc\Enums\Course\GradeBadge;
use Inc\Enums\Course\WorkType;
use Inc\Enums\Subject\TaskTemplate;
use PHPUnit\Framework\TestCase;

class WorkTypeTest extends TestCase {

	public function test_from_value_or_default_returns_valid_type(): void {
		self::assertSame( WorkType::Homework, WorkType::fromValueOrDefault( 'homework' ) );
		self::assertSame( WorkType::Independent, WorkType::fromValueOrDefault( 'independent' ) );
	}

	public function test_from_value_or_default_falls_back_to_practice(): void {
		self::assertSame( WorkType::Practice, WorkType::fromValueOrDefault( 'garbage' ) );
		self::assertSame( WorkType::Practice, WorkType::fromValueOrDefault( '' ) );
	}

	public function test_options_map_value_to_label(): void {
		$options = WorkType::options();
		self::assertArrayHasKey( 'homework', $options );
		self::assertSame( 'Домашняя работа', $options['homework'] );
		self::assertCount( 4, $options );
		self::assertSame( 'Опрос', $options['survey'] );
	}

	public function test_label_is_human_readable(): void {
		self::assertSame( 'Практика', WorkType::Practice->label() );
	}

	public function test_survey_allows_only_simple_question_templates(): void {
		self::assertSame(
			array( TaskTemplate::Choice, TaskTemplate::Matching, TaskTemplate::Ordering, TaskTemplate::Fill, TaskTemplate::DragFill, TaskTemplate::Standard ),
			WorkType::Survey->allowedTemplates()
		);
	}

	public function test_other_work_types_have_no_template_restriction(): void {
		foreach ( array( WorkType::Practice, WorkType::Independent, WorkType::Homework ) as $type ) {
			self::assertNull( $type->allowedTemplates() );
		}
	}

	public function test_survey_has_no_inline_check_and_own_badge(): void {
		self::assertFalse( WorkType::Survey->allowsInlineCheck() );
		self::assertSame( 'ОП', GradeBadge::fromWorkType( WorkType::Survey )->badge() );
	}
}
