<?php

declare( strict_types=1 );

namespace Unit\Enums;

use Inc\Enums\Subject\TaskTemplate;
use PHPUnit\Framework\TestCase;

/** «Опросные» шаблоны — только для работ типа «Опрос»; новый шаблон по умолчанию не опросный. */
class TaskTemplateSurveyOnlyTest extends TestCase {

	public function test_survey_only_is_exactly_the_five_question_templates(): void {
		$surveyOnly = array_values( array_filter( TaskTemplate::cases(), static fn( TaskTemplate $t ): bool => $t->isSurveyOnly() ) );

		self::assertEqualsCanonicalizing(
			array( TaskTemplate::Choice, TaskTemplate::Matching, TaskTemplate::Ordering, TaskTemplate::Fill, TaskTemplate::DragFill ),
			$surveyOnly
		);
	}

	public function test_regular_templates_are_not_survey_only(): void {
		foreach ( array( TaskTemplate::Standard, TaskTemplate::Code, TaskTemplate::File, TaskTemplate::Triple, TaskTemplate::FileAnswer, TaskTemplate::Robo, TaskTemplate::Audio ) as $template ) {
			self::assertFalse( $template->isSurveyOnly(), $template->value );
		}
	}
}
