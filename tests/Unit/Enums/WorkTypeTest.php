<?php

declare( strict_types=1 );

namespace Unit\Enums;

use Inc\Enums\WorkType;
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
		self::assertSame( 'Домашнее задание', $options['homework'] );
		self::assertCount( 3, $options );
	}

	public function test_label_is_human_readable(): void {
		self::assertSame( 'Практика', WorkType::Practice->label() );
	}
}
