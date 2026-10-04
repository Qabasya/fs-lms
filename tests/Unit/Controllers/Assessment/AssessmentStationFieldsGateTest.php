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
use Inc\Enums\Assessment\AssessmentKind;
use Inc\DTO\Assessment\AssessmentDTO;
use Inc\Enums\Assessment\ScoringPolicy;
use Inc\Services\Assessment\AssessmentKindGuard;
use Inc\Services\Course\ContentUsageService;
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
	private AssessmentManager $assessments;
	private ContentUsageService $usage;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_posts();
		unset( $GLOBALS['_fs_test_can'] );

		$this->metaBoxManager = $this->createMock( MetaBoxManager::class );
		$this->assessments    = $this->createMock( AssessmentManager::class );
		$this->usage          = $this->createMock( ContentUsageService::class );
		unset( $GLOBALS['_test_transients'] );

		$this->controller = new AssessmentMetaBoxController(
			$this->createMock( SubjectRepository::class ),
			$this->createMock( MetaBoxRegistrar::class ),
			$this->metaBoxManager,
			new AssessmentTemplate(),
			$this->createMock( PostManager::class ),
			$this->assessments,
			new TaskPublishGuard(),
			$this->createMock( \Inc\Services\Task\TaskBundleService::class ),
			$this->createMock( \Inc\Services\Assessment\AssessmentSlugService::class ),
			new \Inc\Services\Exam\ExamFormatRegistry(),
			new AssessmentKindGuard( $this->assessments, $this->usage ),
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

	private function assessmentOfKind( AssessmentKind $kind ): AssessmentDTO {
		return new AssessmentDTO(
			id: 11, subjectKey: 'inf', title: 'Работа', taskIds: array(), timeLimit: 0, attemptsAllowed: 0, passScore: 0.0,
			scoringPolicy: ScoringPolicy::Highest, status: 'publish', kind: $kind, taskPoints: array(), scoreMap: array(),
		);
	}

	/** @return array<string, mixed> Что ушло в сохранение метабокса. */
	private function saveWithKind( int $postId, string $postedKind ): array {
		fs_test_seed_post( array( 'ID' => $postId, 'post_type' => 'inf_assessments' ) );
		$_POST['fs_lms_meta_nonce'] = 'x';
		$_POST['fs_lms_meta']       = $this->postData( $postedKind );

		$captured = array();
		$this->metaBoxManager->method( 'saveFieldsMerge' )->willReturnCallback( function ( int $id, string $key, array $data ) use ( &$captured ) {
			$captured = $data;
		} );

		$this->controller->handleAssessmentSave( $postId );

		return $captured;
	}

	public function test_kind_cannot_become_station_when_used_in_lesson(): void {
		$this->assessments->method( 'get' )->willReturn( $this->assessmentOfKind( AssessmentKind::Control ) );
		$this->usage->method( 'usageList' )->with( 'assessment', 11 )->willReturn( array( array( 'id' => 5, 'title' => 'Урок 1', 'type' => 'inf_lessons' ) ) );

		$saved = $this->saveWithKind( 11, 'ege_computer' );

		self::assertSame( 'control', $saved['kind'], 'Вид остаётся прежним.' );
		self::assertArrayHasKey( 'time_limit_minutes', $saved, 'Остаётся контрольной — настройки контрольной сохраняются.' );
		self::assertSame(
			'Работа используется в уроках как контрольная: вид «экзамен» недоступен.',
			$GLOBALS['_test_transients'][ 'fs_lms_assessment_kind_blocked_' . get_current_user_id() ] ?? null,
			'Автору показывается предупреждение.'
		);
	}

	public function test_kind_becomes_station_when_not_used_in_lessons(): void {
		$this->assessments->method( 'get' )->willReturn( $this->assessmentOfKind( AssessmentKind::Control ) );
		$this->usage->method( 'usageList' )->willReturn( array() );

		$saved = $this->saveWithKind( 11, 'ege_computer' );

		self::assertSame( 'ege_computer', $saved['kind'] );
		self::assertArrayNotHasKey( 'fs_lms_assessment_kind_blocked_' . get_current_user_id(), $GLOBALS['_test_transients'] ?? array() );
	}
}
