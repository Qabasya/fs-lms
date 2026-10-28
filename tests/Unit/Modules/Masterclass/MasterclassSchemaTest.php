<?php

declare( strict_types=1 );

namespace Unit\Modules\Masterclass;

use Inc\Modules\Masterclass\Schema\MasterclassSchema;
use PHPUnit\Framework\TestCase;

/**
 * DDL таблиц модуля: колонки, типы и ключи ровно по `CONTRACTS.md` §3, переносимый SQL, формат под dbDelta.
 * Создание таблиц на настоящей MariaDB — `tests/stand/masterclass-schema-check.php`.
 */
class MasterclassSchemaTest extends TestCase {

	private const COLLATE = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_test_options'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['_test_options'] = array();
		parent::tearDown();
	}

	/**
	 * Колонки DDL: имя → определение (без запятой, пробелы схлопнуты).
	 *
	 * @return array<string, string>
	 */
	private function columns( string $ddl ): array {
		$columns = array();
		foreach ( explode( "\n", $ddl ) as $line ) {
			$line = trim( preg_replace( '/\s+/', ' ', rtrim( trim( $line ), ',' ) ) );
			if ( 1 === preg_match( '/^(PRIMARY|UNIQUE|KEY|CREATE|\))/', $line ) || '' === $line ) {
				continue;
			}
			[ $name, $definition ]  = explode( ' ', $line, 2 );
			$columns[ $name ]       = $definition;
		}

		return $columns;
	}

	/**
	 * Ключи DDL: список строк `PRIMARY KEY (id)`, `UNIQUE KEY group_id (group_id)`, `KEY freeze_at (freeze_at)`.
	 *
	 * @return list<string>
	 */
	private function keys( string $ddl ): array {
		$keys = array();
		foreach ( explode( "\n", $ddl ) as $line ) {
			$line = trim( preg_replace( '/\s+/', ' ', rtrim( trim( $line ), ',' ) ) );
			if ( 1 === preg_match( '/^(PRIMARY|UNIQUE KEY|KEY) /', $line ) ) {
				$keys[] = $line;
			}
		}

		return $keys;
	}

	public function test_table_names_carry_the_wordpress_prefix(): void {
		self::assertSame( 'wp_fs_lms_masterclasses', MasterclassSchema::masterclasses() );
		self::assertSame( 'wp_fs_lms_masterclass_accounts', MasterclassSchema::accounts() );
		self::assertSame( 'wp_fs_lms_masterclass_log', MasterclassSchema::log() );
	}

	public function test_all_tables_share_the_uninstall_prefix(): void {
		foreach ( array( MasterclassSchema::masterclasses(), MasterclassSchema::accounts(), MasterclassSchema::log() ) as $table ) {
			self::assertStringStartsWith( 'wp_fs_lms_', $table, 'общий uninstall.php сносит таблицы по префиксу fs_lms_' );
		}
	}

	public function test_masterclasses_columns_follow_the_contract(): void {
		$ddl = ( new MasterclassSchema() )->masterclassesDdl( self::COLLATE );

		self::assertSame(
			array(
				'id'                  => 'int unsigned NOT NULL AUTO_INCREMENT',
				'school_name'         => 'varchar(255) NOT NULL',
				'subject_key'         => 'varchar(50) NOT NULL',
				'lesson_id'           => 'bigint(20) unsigned NOT NULL',
				'group_id'            => 'smallint unsigned DEFAULT NULL',
				'group_lesson_id'     => 'int unsigned DEFAULT NULL',
				'teacher_user_id'     => 'bigint(20) unsigned NOT NULL',
				'starts_at'           => 'datetime NOT NULL',
				'ends_at'             => 'datetime NOT NULL',
				'freeze_at'           => 'datetime NOT NULL',
				'unused_purge_at'     => 'datetime NOT NULL',
				'purge_at'            => 'datetime NOT NULL',
				'frozen_at'           => 'datetime DEFAULT NULL',
				'unused_purged_at'    => 'datetime DEFAULT NULL',
				'delete_requested_at' => 'datetime DEFAULT NULL',
				'purged_at'           => 'datetime DEFAULT NULL',
				'created_by_user_id'  => 'bigint(20) unsigned DEFAULT NULL',
				'created_at'          => 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP',
				'updated_at'          => 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
			),
			$this->columns( $ddl )
		);
		self::assertSame(
			array(
				'PRIMARY KEY (id)',
				'UNIQUE KEY group_id (group_id)',
				'KEY freeze_at (freeze_at)',
				'KEY unused_purge_at (unused_purge_at)',
				'KEY purge_at (purge_at)',
				'KEY purged_at (purged_at)',
			),
			$this->keys( $ddl )
		);
	}

	public function test_group_id_has_the_type_of_groups_id(): void {
		// `groups.id` — smallint unsigned: больший id БД молча обрежет, и ссылка поедет на чужую группу.
		self::assertStringContainsString( 'group_id            smallint unsigned', ( new MasterclassSchema() )->masterclassesDdl( self::COLLATE ) );
	}

	public function test_accounts_columns_follow_the_contract(): void {
		$ddl = ( new MasterclassSchema() )->accountsDdl( self::COLLATE );

		self::assertSame(
			array(
				'id'                => 'int unsigned NOT NULL AUTO_INCREMENT',
				'masterclass_id'    => 'int unsigned NOT NULL',
				'user_id'           => 'bigint(20) unsigned DEFAULT NULL',
				'person_id'         => 'int unsigned DEFAULT NULL',
				'student_record_id' => 'int unsigned DEFAULT NULL',
				'state'             => "varchar(10) NOT NULL DEFAULT 'active'",
				'first_login_at'    => 'datetime DEFAULT NULL',
				'home_at'           => 'datetime DEFAULT NULL',
				'contact_at'        => 'datetime DEFAULT NULL',
				'application_id'    => 'int unsigned DEFAULT NULL',
				'deleted_at'        => 'datetime DEFAULT NULL',
				'delete_reason'     => 'varchar(20) DEFAULT NULL',
				'created_at'        => 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP',
				'updated_at'        => 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
			),
			$this->columns( $ddl )
		);
		self::assertSame(
			array(
				'PRIMARY KEY (id)',
				'UNIQUE KEY user_id (user_id)',
				'KEY masterclass_id (masterclass_id)',
				'KEY person_id (person_id)',
				'KEY deleted_at (deleted_at)',
			),
			$this->keys( $ddl )
		);
	}

	public function test_log_columns_follow_the_exam_log_pattern(): void {
		$ddl = ( new MasterclassSchema() )->logDdl( self::COLLATE );

		self::assertSame(
			array(
				'id'             => 'int unsigned NOT NULL AUTO_INCREMENT',
				'action'         => 'varchar(40) NOT NULL',
				'result'         => "varchar(10) NOT NULL DEFAULT 'success'",
				'actor_type'     => "varchar(10) NOT NULL DEFAULT 'system'",
				'actor_user_id'  => 'bigint(20) unsigned DEFAULT NULL',
				'actor_label'    => 'varchar(255) DEFAULT NULL',
				'masterclass_id' => 'int unsigned DEFAULT NULL',
				'account_id'     => 'int unsigned DEFAULT NULL',
				'subject_label'  => 'varchar(255) DEFAULT NULL',
				'actor_ip'       => "varchar(45) NOT NULL DEFAULT ''",
				'context'        => 'longtext DEFAULT NULL',
				'created_at'     => 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP',
			),
			$this->columns( $ddl )
		);
		self::assertSame(
			array(
				'PRIMARY KEY (id)',
				'KEY action (action)',
				'KEY masterclass_id (masterclass_id)',
				'KEY account_id (account_id)',
				'KEY created_at (created_at)',
			),
			$this->keys( $ddl )
		);
	}

	public function test_ddl_is_portable_and_dbdelta_friendly(): void {
		$schema = new MasterclassSchema();

		foreach ( array( $schema->masterclassesDdl( self::COLLATE ), $schema->accountsDdl( self::COLLATE ), $schema->logDdl( self::COLLATE ) ) as $ddl ) {
			self::assertStringNotContainsString( 'IF NOT EXISTS', $ddl, 'у колонок и таблиц — только переносимый SQL (MySQL тихо падает на MariaDB-синтаксисе)' );
			self::assertMatchesRegularExpression( '/PRIMARY KEY  \(id\)/', $ddl, 'dbDelta требует два пробела после PRIMARY KEY' );
			self::assertStringContainsString( self::COLLATE, $ddl );
			self::assertStringEndsWith( ';', $ddl );
			foreach ( explode( "\n", $ddl ) as $line ) {
				self::assertSame( 0, substr_count( rtrim( trim( $line ), ',' ), ',' ), 'каждая колонка — в своей строке: ' . trim( $line ) );
			}
		}
	}

	public function test_ensure_does_nothing_when_the_schema_version_is_current(): void {
		$GLOBALS['_test_options'][ MasterclassSchema::VERSION_OPTION ] = MasterclassSchema::VERSION;

		// dbDelta в юнит-окружении не определён: попытка вызвать его при актуальной версии упала бы.
		( new MasterclassSchema() )->ensure();

		self::assertSame( MasterclassSchema::VERSION, $GLOBALS['_test_options'][ MasterclassSchema::VERSION_OPTION ] );
	}

	public function test_version_option_name_is_stable(): void {
		self::assertSame( 'fs_lms_masterclass_schema_version', MasterclassSchema::VERSION_OPTION );
		self::assertSame( '1', MasterclassSchema::VERSION );
	}
}
