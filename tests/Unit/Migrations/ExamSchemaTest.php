<?php

declare( strict_types=1 );

namespace Tests\Unit\Migrations;

use Inc\Migrations\Migration_1_0_71;
use Inc\Enums\Settings\TableName;
use PHPUnit\Framework\TestCase;

class ExamSchemaTest extends TestCase {

	private string $cc = 'utf8mb4_unicode_ci';

	public function test_migration_version_matches_class_name(): void {
		$class = new \ReflectionClass( Migration_1_0_71::class );

		self::assertSame( '1.0.71', ( new Migration_1_0_71() )->version() );
		self::assertSame( 'Migration_' . str_replace( '.', '_', ( new Migration_1_0_71() )->version() ), $class->getShortName() );
	}

	public function test_ddl_contains_fifteen_tables(): void {
		$ddl = Migration_1_0_71::examDdl( $this->cc );

		$this->assertCount( 15, $ddl, 'DDL должна содержать ровно 15 таблиц' );
	}

	public function test_every_table_name_comes_from_table_name_enum(): void {
		$ddl = Migration_1_0_71::examDdl( $this->cc );

		$expectedTables = array(
			TableName::ExamEvents->prefixed(),
			TableName::ExamSessions->prefixed(),
			TableName::ExamParticipants->prefixed(),
			TableName::ExamParticipations->prefixed(),
			TableName::ExamRegistrations->prefixed(),
			TableName::ExamSources->prefixed(),
			TableName::ExamAccessTokens->prefixed(),
			TableName::ExamGuestSessions->prefixed(),
			TableName::ExamReports->prefixed(),
			TableName::ExamReportMembers->prefixed(),
			TableName::ExamOutbox->prefixed(),
			TableName::ExamOperationKeys->prefixed(),
			TableName::ExamGuestApplications->prefixed(),
			TableName::ExamPaymentLinks->prefixed(),
			TableName::ExamManualResolutions->prefixed(),
		);

		foreach ( $ddl as $sql ) {
			$found = false;
			foreach ( $expectedTables as $table ) {
				if ( strpos( $sql, "CREATE TABLE $table" ) === 0 ) {
					$found = true;
					break;
				}
			}
			$this->assertTrue( $found, "SQL содержит CREATE TABLE которого нет в TableName enum: $sql" );
		}
	}

	public function test_primary_key_has_two_spaces(): void {
		$ddl = Migration_1_0_71::examDdl( $this->cc );

		foreach ( $ddl as $sql ) {
			$this->assertStringContainsString( 'PRIMARY KEY  (id)', $sql, "DDL должна содержать ровно два пробела перед (id): $sql" );
		}
	}

	public function test_registrations_have_active_slot_unique_index(): void {
		$ddl = Migration_1_0_71::examDdl( $this->cc );

		$registrationsDdl = '';
		foreach ( $ddl as $sql ) {
			if ( strpos( $sql, 'exam_registrations' ) !== false ) {
				$registrationsDdl = $sql;
				break;
			}
		}

		$this->assertNotEmpty( $registrationsDdl, 'Должна быть таблица exam_registrations' );
		$this->assertStringContainsString( 'UNIQUE KEY participation_active (participation_id, active_slot)', $registrationsDdl );
	}

	public function test_no_foreign_keys_and_no_if_not_exists(): void {
		$ddl = Migration_1_0_71::examDdl( $this->cc );

		foreach ( $ddl as $sql ) {
			$this->assertStringNotContainsString( 'FOREIGN KEY', $sql, 'Не должно быть внешних ключей' );
			$this->assertStringNotContainsString( 'IF NOT EXISTS', $sql, 'Не должно быть IF NOT EXISTS' );
		}
	}

	/** Текст файла миграции: проверка итоговой схемы для установки с нуля (в юнит-тесте базы нет — смоук на MariaDB описан в NOTES.md). */
	private function source( string $class ): string {
		return (string) file_get_contents( ( new \ReflectionClass( $class ) )->getFileName() );
	}

	public function test_fresh_install_builds_exam_tables_from_the_same_ddl(): void {
		$source = $this->source( \Inc\Migrations\Migration_1_0_0::class );

		self::assertStringContainsString( 'Migration_1_0_71::examDdl( $cc )', $source, 'Новая установка получает ту же схему, что и апгрейд.' );
		foreach ( TableName::cases() as $case ) {
			if ( str_starts_with( $case->value, 'fs_lms_exam_' ) ) {
				self::assertStringContainsString( 'TableName::' . $case->name . '->prefixed()', $source, "down() новой установки удаляет {$case->value}" );
			}
		}
	}

	public function test_fresh_install_attempts_table_has_exam_columns_and_nullable_student(): void {
		$source = $this->source( \Inc\Migrations\Migration_1_0_0::class );
		$start  = strpos( $source, '$assessment_attempts = TableName::AssessmentAttempts->prefixed();' );
		$ddl    = substr( $source, (int) $start, 2200 );

		foreach ( array( 'exam_participation_id', 'exam_registration_id', 'result_version', 'UNIQUE KEY exam_participation (exam_participation_id)' ) as $needle ) {
			self::assertStringContainsString( $needle, $ddl, $needle );
		}
		self::assertMatchesRegularExpression( '/student_person_id\s+int unsigned\s+DEFAULT NULL/', $ddl, 'Гостевая попытка: ученика может не быть.' );
	}

	public function test_down_removes_attempt_index_and_columns_but_keeps_student_nullable(): void {
		$source = $this->source( Migration_1_0_71::class );
		$from   = (int) strpos( $source, 'public function down()' );
		$down   = substr( $source, $from, (int) strpos( $source, 'public static function examDdl' ) - $from );

		self::assertStringContainsString( 'DROP INDEX exam_participation', $down );
		foreach ( array( 'exam_participation_id', 'exam_registration_id', 'result_version' ) as $column ) {
			self::assertStringContainsString( "'{$column}'", $down );
		}
		self::assertStringNotContainsString( 'MODIFY student_person_id', $down, 'student_person_id обратно в NOT NULL не возвращается.' );
	}

	public function test_consent_version_column_fits_sha256_hash(): void {
		self::assertSame( '1.0.72', ( new \Inc\Migrations\Migration_1_0_72() )->version() );
		self::assertStringContainsString( 'version              varchar(64)', (string) file_get_contents( dirname( __DIR__, 3 ) . '/inc/Migrations/Migration_1_0_0.php' ) );
	}
}
