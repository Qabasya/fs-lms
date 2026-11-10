<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Settings;

use Inc\Migrations\RankingSubjectMigration;
use Inc\Repositories\OptionsRepositories\PluginConfigRepository;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\DTO\Subject\SubjectDTO;
use Inc\Services\Shared\PluginConfig;
use PHPUnit\Framework\TestCase;

class RankingSubjectMigrationTest extends TestCase {
	public function test_recreation_clears_settings_after_cleanup_failure(): void {
		$before = $GLOBALS['_test_options'] ?? array();
		try {
			$repo = new PluginConfigRepository();
			$subjects = new SubjectRepository();
			$subjects->save( new SubjectDTO( 'math', 'Math' ) );
			$repo->save( array( 'ranking_subjects_version' => 1, 'ranking_subjects' => array( 'math' => array( 'enabled' => true ) ) ) );
			$GLOBALS['_test_fail_option_name'] = \Inc\Enums\Settings\OptionName::PluginConfig->value;
			try {
				$subjects->remove( 'math' );
				self::fail( 'Cleanup failure must be reported.' );
			} catch ( \RuntimeException $expected ) {
				self::assertNull( $subjects->getByKey( 'math' ) );
			}
			unset( $GLOBALS['_test_fail_option_name'] );
			self::assertTrue( $subjects->save( new SubjectDTO( 'math', 'New Math' ) ) );
			self::assertFalse( ( new PluginConfig( $repo ) )->rankingForSubject( 'math' )['enabled'] );
		} finally {
			unset( $GLOBALS['_test_fail_option_name'] );
			$GLOBALS['_test_options'] = $before;
		}
	}
	public function test_failed_creation_does_not_publish_a_subject_with_stale_settings(): void {
		$before = $GLOBALS['_test_options'] ?? array();
		try {
			$repo = new PluginConfigRepository();
			$subjects = new SubjectRepository();
			$repo->save( array( 'ranking_subjects_version' => 1, 'ranking_subjects' => array( 'math' => array( 'enabled' => true, 'practice' => 7.0 ) ) ) );
			$GLOBALS['_test_fail_option_name'] = \Inc\Enums\Settings\OptionName::PluginConfig->value;
			try {
				$subjects->save( new SubjectDTO( 'math', 'Math' ) );
				self::fail( 'Creation must fail if the settings cannot be reset.' );
			} catch ( \RuntimeException $expected ) {
				self::assertNull( $subjects->getByKey( 'math' ) );
			}
			unset( $GLOBALS['_test_fail_option_name'] );
			self::assertTrue( $subjects->save( new SubjectDTO( 'math', 'Math' ) ) );
			self::assertFalse( ( new PluginConfig( $repo ) )->rankingForSubject( 'math' )['enabled'] );
		} finally {
			unset( $GLOBALS['_test_fail_option_name'] );
			$GLOBALS['_test_options'] = $before;
		}
	}
	public function test_deleted_and_recreated_subject_does_not_inherit_its_old_ranking(): void {
		$before = $GLOBALS['_test_options'] ?? array();
		try {
			$repo = new PluginConfigRepository();
			$subjects = new SubjectRepository();
			$subjects->save( new SubjectDTO( 'math', 'Math' ) );
			$repo->save( array( 'ranking_subjects_version' => 1, 'ranking_subjects' => array( 'math' => array( 'enabled' => true, 'practice' => 7.0 ) ) ) );
			$old = ( new PluginConfig( $repo ) )->rankingForSubject( 'math' );
			$subjects->remove( 'math' );
			$subjects->save( new SubjectDTO( 'math', 'New Math' ) );
			$policy = ( new PluginConfig( $repo ) )->rankingForSubject( 'math' );
			self::assertFalse( $policy['enabled'] );
			self::assertSame( 0.0, $policy['practice'] );
			self::assertNotSame( $old['generation'], $policy['generation'] );
		} finally {
			$GLOBALS['_test_options'] = $before;
		}
	}
	public function test_subject_exclusions_are_combined_once_and_apply_to_every_subject(): void {
		$before = $GLOBALS['_test_options'] ?? array();
		$repository = new PluginConfigRepository();
		$repository->save( array( 'ranking_subjects_version' => 1, 'ranking_excluded_students' => array( 1 ), 'ranking_subjects' => array( 'math' => array( 'excluded_students' => array( 2, 3 ) ), 'physics' => array( 'excluded_students' => array( 3, 4 ) ) ) ) );
		$config = new PluginConfig( $repository );
		$migration = new RankingSubjectMigration( $repository, new SubjectRepository(), $config );
		$migration->run();
		self::assertSame( array( 1, 2, 3, 4 ), $config->rankingForSubject( 'math' )['excluded_students'] );
		self::assertSame( array( 1, 2, 3, 4 ), $config->rankingForSubject( 'physics' )['excluded_students'] );
		$repository->save( array( 'ranking_excluded_students' => array( 4 ) ) );
		$migration->run();
		self::assertSame( array( 4 ), $config->rankingForSubject( 'math' )['excluded_students'] );
		$GLOBALS['_test_options'] = $before;
	}
	private mixed $previousDb;

	protected function setUp(): void {
		$this->previousDb = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = new \wpdb();
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->previousDb;
	}
	public function test_existing_subjects_keep_effective_settings_and_new_subjects_default_to_disabled(): void {
		$before = $GLOBALS['_test_options'] ?? array();
		try {
			$GLOBALS['_test_options'] = array();
			$repository = new PluginConfigRepository();
			$subjects = new SubjectRepository();
			$subjects->save( new SubjectDTO( 'math', 'Math' ) );
			$subjects->save( new SubjectDTO( 'physics', 'Physics', archived: true ) );
			$repository->save( array( 'ranking_practice' => 1.257, 'ranking_hidden_subjects' => array( 'physics' ), 'ranking_excluded_students' => array( 42 ) ) );
			$config = new PluginConfig( $repository );
			$migration = new RankingSubjectMigration( $repository, $subjects, $config );
			$migration->run();
			self::assertSame( 1.257, $config->rankingForSubject( 'math' )['practice'] );
			self::assertTrue( $config->rankingForSubject( 'math' )['enabled'] );
			self::assertFalse( $config->rankingForSubject( 'physics' )['enabled'] );
			self::assertSame( array( 42 ), $config->rankingForSubject( 'physics' )['excluded_students'] );
			$repository->removeRankingSubject( 'math' );
			self::assertFalse( $config->rankingForSubject( 'math' )['enabled'] );
			self::assertSame( array( 42 ), $config->rankingForSubject( 'physics' )['excluded_students'] );
			$map = $repository->get()['ranking_subjects'];
			$map['math']['practice'] = 7.0;
			$repository->save( array( 'ranking_subjects' => $map ) );
			$migration->run();
			self::assertSame( 7.0, $config->rankingForSubject( 'math' )['practice'] );
			$repository->saveRankingSubject( 'math', array( 'enabled' => false ) );
			self::assertSame( 7.0, $config->rankingForSubject( 'math' )['practice'] );
			self::assertSame( array( 42 ), $config->rankingForSubject( 'physics' )['excluded_students'] );
			self::assertFalse( $config->rankingForSubject( 'new_subject' )['enabled'] );
			self::assertSame( 0.0, $config->rankingForSubject( 'new_subject' )['practice'] );
		} finally {
			$GLOBALS['_test_options'] = $before;
		}
	}
}
