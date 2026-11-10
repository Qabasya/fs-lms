<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Settings;

use Inc\DTO\Subject\SubjectDTO;
use Inc\Repositories\OptionsRepositories\PluginConfigRepository;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Services\Settings\RankingSettingsService;
use PHPUnit\Framework\TestCase;

class RankingSubjectSaveTest extends TestCase {
	public function test_empty_unknown_card_is_rejected(): void {
		$oldDb = $GLOBALS['wpdb'];
		$before = $GLOBALS['_test_options'] ?? array();
		$GLOBALS['wpdb'] = new \wpdb();
		try {
			$repo = new PluginConfigRepository();
			$repo->save( array( 'ranking_subjects_version' => 1 ) );
			$this->expectException( \InvalidArgumentException::class );
			( new RankingSettingsService( $repo, null, new SubjectRepository() ) )->saveSubjects( array( 'missing' => array() ) );
		} finally {
			$GLOBALS['wpdb'] = $oldDb;
			$GLOBALS['_test_options'] = $before;
		}
	}
	public function test_patch_is_atomic_and_does_not_replace_neighbouring_subjects(): void {
		$before = $GLOBALS['_test_options'] ?? array();
		$oldDb = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = new \wpdb();
		try {
			$repo = new PluginConfigRepository();
			$subjects = new SubjectRepository();
			$subjects->save( new SubjectDTO( 'math', 'Math' ) );
			$subjects->save( new SubjectDTO( 'physics', 'Physics' ) );
			$repo->save( array( 'ranking_subjects_version' => 1, 'ranking_excluded_students' => array(), 'ranking_subjects' => array( 'math' => array( 'practice' => 1.257, 'enabled' => true ), 'physics' => array( 'practice' => 3.0 ) ) ) );
			$service = new RankingSettingsService( $repo, null, $subjects );
			try {
				$service->saveSubjects( array( 'math' => array( 'enabled' => '0' ), 'physics' => array( 'practice' => 'bad' ) ), array( 'excluded_students' => '4' ) );
				self::fail( 'Invalid card must reject the batch.' );
			} catch ( \InvalidArgumentException $e ) {
				self::assertStringContainsString( 'physics', $e->getMessage() );
				self::assertStringContainsString( 'Практика', $e->getMessage() );
				self::assertTrue( $repo->get()['ranking_subjects']['math']['enabled'] );
				self::assertSame( array(), $repo->get()['ranking_excluded_students'] );
			}
			$service->saveSubjects( array( 'math' => array( 'enabled' => '0' ) ), array( 'excluded_students' => '4,5' ) );
			self::assertSame( array( 4, 5 ), $repo->get()['ranking_excluded_students'] );
			$service->saveSubjects( array(), array( 'excluded_students' => '' ) );
			self::assertSame( array(), $repo->get()['ranking_excluded_students'] );
			$map = $repo->get()['ranking_subjects'];
			self::assertFalse( $map['math']['enabled'] );
			self::assertSame( 1.257, $map['math']['practice'] );
			self::assertSame( 3.0, $map['physics']['practice'] );
			self::assertSame( 1, $map['math']['revision'] );
		} finally {
			$GLOBALS['_test_options'] = $before;
			$GLOBALS['wpdb'] = $oldDb;
		}
	}
}
