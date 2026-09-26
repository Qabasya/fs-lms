<?php

declare( strict_types=1 );

namespace Unit\Enums;

use Inc\Enums\Subject\TaskTemplate;
use Inc\MetaBoxes\Templates\RoboTaskTemplate;
use PHPUnit\Framework\TestCase;

/**
 * «Задание Робо»: ручная проверка, ответ — только код, материалы — вложения.
 */
class TaskTemplateRoboTest extends TestCase {

	public function test_robo_is_manual_code_only_template(): void {
		$robo = TaskTemplate::Robo;

		self::assertSame( RoboTaskTemplate::class, $robo->class() );
		self::assertTrue( $robo->needsManualReview() );
		self::assertTrue( $robo->isCodeOnlyAnswer() );
		self::assertTrue( $robo->hasCodeField() );
		self::assertTrue( $robo->hasTaskMaterials() );
		// Не файловая форма: загрузки файлов в ответе у Робо нет.
		self::assertFalse( $robo->isFileAnswerShape() );
	}

	public function test_manual_review_covers_all_manual_templates_only(): void {
		$manual = array_values( array_filter(
			TaskTemplate::cases(),
			static fn( TaskTemplate $t ): bool => $t->needsManualReview()
		) );

		self::assertSame(
			array( TaskTemplate::FileAnswer, TaskTemplate::AlternativeConditions, TaskTemplate::Robo ),
			$manual
		);
	}

	public function test_code_only_answer_is_robo_alone(): void {
		foreach ( TaskTemplate::cases() as $template ) {
			self::assertSame( TaskTemplate::Robo === $template, $template->isCodeOnlyAnswer(), $template->value );
		}
	}

	public function test_template_id_matches_enum_value(): void {
		self::assertSame( TaskTemplate::Robo->value, ( new RoboTaskTemplate() )->get_id() );
	}
}
