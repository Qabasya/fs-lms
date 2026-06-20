<?php

declare( strict_types=1 );

namespace Unit\Enums;

use Inc\Enums\Course\StepType;
use PHPUnit\Framework\TestCase;

class StepTypeTest extends TestCase {

	public function test_from_value_or_default_returns_valid_type(): void {
		self::assertSame( StepType::Work, StepType::fromValueOrDefault( 'work' ) );
		self::assertSame( StepType::Assessment, StepType::fromValueOrDefault( 'assessment' ) );
	}

	public function test_from_value_or_default_falls_back_to_text(): void {
		self::assertSame( StepType::Text, StepType::fromValueOrDefault( 'garbage' ) );
		self::assertSame( StepType::Text, StepType::fromValueOrDefault( '' ) );
	}

	public function test_inline_and_ref_partition(): void {
		foreach ( array( StepType::Text, StepType::Video, StepType::Material ) as $inline ) {
			self::assertTrue( $inline->isInline(), $inline->value );
			self::assertFalse( $inline->isRef(), $inline->value );
		}
		foreach ( array( StepType::Task, StepType::Work, StepType::Assessment ) as $ref ) {
			self::assertTrue( $ref->isRef(), $ref->value );
			self::assertFalse( $ref->isInline(), $ref->value );
		}
	}

	public function test_allowed_types_for_lesson_is_all(): void {
		self::assertSame( StepType::cases(), StepType::allowedTypesFor( StepType::LEVEL_LESSON ) );
	}

	public function test_allowed_types_for_work_and_assessment_is_task_only(): void {
		self::assertSame( array( StepType::Task ), StepType::allowedTypesFor( StepType::LEVEL_WORK ) );
		self::assertSame( array( StepType::Task ), StepType::allowedTypesFor( StepType::LEVEL_ASSESSMENT ) );
	}

	public function test_allowed_types_for_unknown_level_is_empty(): void {
		self::assertSame( array(), StepType::allowedTypesFor( 'whatever' ) );
	}

	public function test_allowed_for(): void {
		self::assertTrue( StepType::Video->allowedFor( StepType::LEVEL_LESSON ) );
		self::assertTrue( StepType::Task->allowedFor( StepType::LEVEL_WORK ) );
		self::assertFalse( StepType::Video->allowedFor( StepType::LEVEL_WORK ) );
		self::assertFalse( StepType::Work->allowedFor( StepType::LEVEL_ASSESSMENT ) );
	}

	public function test_options_map_value_to_label(): void {
		$options = StepType::options();
		self::assertCount( 6, $options );
		self::assertSame( 'Контрольная', $options['assessment'] );
		self::assertSame( 'Текст', $options['text'] );
	}
}
