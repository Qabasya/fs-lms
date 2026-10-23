<?php

declare( strict_types=1 );

namespace Tests\Unit\Migrations;

use Inc\Enums\Settings\TableName;
use Inc\Migrations\Migration_1_0_94;
use PHPUnit\Framework\TestCase;

/** Доводка колонок 1.0.86–1.0.93 не должна использовать синтаксис, которого нет в MySQL. */
class RepairColumnsMigrationTest extends TestCase {

	public function test_version_matches_class_name(): void {
		self::assertSame( '1.0.94', ( new Migration_1_0_94() )->version() );
	}

	public function test_add_column_sql_is_portable(): void {
		$sql = Migration_1_0_94::addColumnSql( 'wp_t', 'c', 'int DEFAULT NULL' );

		self::assertSame( 'ALTER TABLE `wp_t` ADD COLUMN `c` int DEFAULT NULL', $sql );
		self::assertStringNotContainsString( 'IF NOT EXISTS', $sql );
	}

	public function test_session_visibility_column_is_covered(): void {
		$pairs = array_map( static fn ( array $s ): string => $s['table']->value . '.' . $s['column'], Migration_1_0_94::columns() );

		self::assertContains( TableName::ExamSessions->value . '.visible_to_students', $pairs );
		self::assertContains( TableName::ExamSources->value . '.session_ids', $pairs );
		self::assertContains( TableName::TaskAttempts->value . '.first_answer_sec', $pairs );
	}

	public function test_survey_enum_migration_version(): void {
		self::assertSame( '1.0.98', ( new \Inc\Migrations\Migration_1_0_98() )->version() );
	}
}
