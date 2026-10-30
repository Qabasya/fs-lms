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

	/** Остальные типы — «все шаблоны, кроме опросных»: список, а не «без ограничений». */
	public function test_other_work_types_allow_everything_except_survey_only_templates(): void {
		$expected = array_values( array_filter( TaskTemplate::cases(), static fn( TaskTemplate $t ): bool => ! $t->isSurveyOnly() ) );

		foreach ( array( WorkType::Practice, WorkType::Independent, WorkType::Homework ) as $type ) {
			self::assertEqualsCanonicalizing( $expected, $type->allowedTemplates(), $type->value );
			self::assertContains( TaskTemplate::Standard, $type->allowedTemplates() );
			self::assertContains( TaskTemplate::Code, $type->allowedTemplates() );
		}
	}

	public function test_no_work_type_returns_null_allowed_templates(): void {
		foreach ( WorkType::cases() as $type ) {
			self::assertIsArray( $type->allowedTemplates(), $type->value );
		}
	}

	public function test_survey_only_templates_are_allowed_only_in_survey(): void {
		foreach ( TaskTemplate::cases() as $template ) {
			if ( ! $template->isSurveyOnly() ) {
				continue;
			}
			self::assertContains( $template, WorkType::Survey->allowedTemplates(), $template->value );
			foreach ( array( WorkType::Practice, WorkType::Independent, WorkType::Homework ) as $type ) {
				self::assertNotContains( $template, $type->allowedTemplates(), $template->value . ' / ' . $type->value );
			}
		}
	}

	public function test_survey_has_no_inline_check_and_own_badge(): void {
		self::assertFalse( WorkType::Survey->allowsInlineCheck() );
		self::assertSame( 'ОП', GradeBadge::fromWorkType( WorkType::Survey )->badge() );
	}
}
