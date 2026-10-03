<?php

declare( strict_types=1 );

namespace Tests\Unit\Migrations;

use Inc\Migrations\Migration_1_0_71;
use Inc\Enums\Settings\TableName;
use PHPUnit\Framework\TestCase;

class ExamSchemaTest extends TestCase {

	private string $cc = 'utf8mb4_unicode_ci';

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
}
