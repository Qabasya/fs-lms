<?php

declare( strict_types=1 );

namespace Unit\Controllers\Assessment;

use Inc\Controllers\Assessment\AssessmentMetaBoxController;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Managers\Wp\MetaBoxManager;
use Inc\Managers\Wp\PostManager;
use Inc\MetaBoxes\Templates\AssessmentTemplate;
use Inc\Registrars\MetaBoxRegistrar;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Services\Assessment\EgeCompletenessChecker;
use Inc\Services\Task\TaskPublishGuard;
use PHPUnit\Framework\TestCase;

/**
 * Станции (ЕГЭ/ОГЭ) имитируют реальный экзамен: время/попытки/вступительный
 * текст больше не сохраняются из формы, даже если пришли в $_POST (см.
 * .docs/Tasks.md, §3.2). score_map стрипается всегда, независимо от вида:
 * для станций шкала приходит из module-level StationExamConfig и безусловно
 * переопределяет значение из меты при каждом чтении (EgeComputerModule::
 * applyStationSettings()), у Control поле не читается вовсе — мета-версия
 * мертва в обоих случаях.
 */
class AssessmentStationFieldsGateTest extends TestCase {

	private MetaBoxManager $metaBoxManager;
	private AssessmentMetaBoxController $controller;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_posts();
		unset( $GLOBALS['_fs_test_can'] );

		$this->metaBoxManager = $this->createMock( MetaBoxManager::class );

		$this->controller = new AssessmentMetaBoxController(
			$this->createMock( SubjectRepository::class ),
			$this->createMock( MetaBoxRegistrar::class ),
			$this->metaBoxManager,
			new AssessmentTemplate(),
			$this->createMock( PostManager::class ),
			$this->createMock( AssessmentManager::class ),
			new TaskPublishGuard(),
			$this->createMock( EgeCompletenessChecker::class ),
		);
	}

	private function postData( string $kind ): array {
		return array(
			'kind'                => $kind,
			'time_limit_minutes'  => '999',
			'max_attempts'        => '999',
			'score_map'           => "0\t0\n1\t7",
			'intro_html'          => '<p>кастом</p>',
		);
	}

	public function test_station_kind_strips_time_attempts_score_map_intro(): void {
		fs_test_seed_post( array( 'ID' => 7, 'post_type' => 'inf_assessments' ) );
		$_POST['fs_lms_meta_nonce'] = 'x';
		$_POST['fs_lms_meta']       = $this->postData( 'ege_computer' );

		$captured = null;
		$this->metaBoxManager->expects( self::once() )
			->method( 'saveFieldsMerge' )
			->willReturnCallback( function ( int $id, string $key, array $data ) use ( &$captured ) {
				$captured = $data;
			} );

		$this->controller->handleAssessmentSave( 7 );

		self::assertArrayNotHasKey( 'time_limit_minutes', $captured );
		self::assertArrayNotHasKey( 'max_attempts', $captured );
		self::assertArrayNotHasKey( 'score_map', $captured );
		self::assertArrayNotHasKey( 'intro_html', $captured );
		self::assertSame( 'ege_computer', $captured['kind'] );
	}

	public function test_oge_computer_also_strips_station_fields(): void {
		fs_test_seed_post( array( 'ID' => 8, 'post_type' => 'inf_assessments' ) );
		$_POST['fs_lms_meta_nonce'] = 'x';
		$_POST['fs_lms_meta']       = $this->postData( 'oge_computer' );

		$captured = null;
		$this->metaBoxManager->method( 'saveFieldsMerge' )
			->willReturnCallback( function ( int $id, string $key, array $data ) use ( &$captured ) {
				$captured = $data;
			} );

		$this->controller->handleAssessmentSave( 8 );

		self::assertArrayNotHasKey( 'time_limit_minutes', $captured );
		self::assertArrayNotHasKey( 'score_map', $captured );
	}

	public function test_control_kind_keeps_time_and_intro_but_strips_score_map(): void {
		fs_test_seed_post( array( 'ID' => 9, 'post_type' => 'inf_assessments' ) );
		$_POST['fs_lms_meta_nonce'] = 'x';
		$_POST['fs_lms_meta']       = $this->postData( 'control' );

		$captured = null;
		$this->metaBoxManager->method( 'saveFieldsMerge' )
			->willReturnCallback( function ( int $id, string $key, array $data ) use ( &$captured ) {
				$captured = $data;
			} );

		$this->controller->handleAssessmentSave( 9 );

		self::assertArrayHasKey( 'time_limit_minutes', $captured );
		self::assertArrayHasKey( 'max_attempts', $captured );
		self::assertArrayNotHasKey( 'score_map', $captured );
		self::assertArrayHasKey( 'intro_html', $captured );
	}
}
