<?php

declare( strict_types=1 );

namespace Unit\Services\Task;

use Inc\DTO\Assessment\AssessmentDTO;
use Inc\DTO\Course\WorkDTO;
use Inc\DTO\Subject\SubjectDTO;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Assessment\ScoringPolicy;
use Inc\Enums\Course\WorkType;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Managers\Course\WorkManager;
use Inc\Managers\Wp\PostManager;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Services\Task\TaskBundleMigrationPlanner;
use Inc\Services\Task\TaskBundleService;
use PHPUnit\Framework\TestCase;

/**
 * TaskBundleMigrationPlanner: план и применение переезда ссылок Work/Assessment
 * на children связки 19-21 (см. .docs/Tasks.md, §4).
 */
class TaskBundleMigrationPlannerTest extends TestCase {

	private PostManager $posts;
	private SubjectRepository $subjects;
	private TaskBundleService $bundles;
	private WorkManager $works;
	private AssessmentManager $assessments;
	private TaskBundleMigrationPlanner $planner;

	protected function setUp(): void {
		parent::setUp();
		$this->posts       = $this->createMock( PostManager::class );
		$this->subjects     = $this->createMock( SubjectRepository::class );
		$this->bundles      = $this->createMock( TaskBundleService::class );
		$this->works        = $this->createMock( WorkManager::class );
		$this->assessments  = $this->createMock( AssessmentManager::class );

		$this->planner = new TaskBundleMigrationPlanner(
			$this->posts,
			$this->subjects,
			$this->bundles,
			$this->works,
			$this->assessments
		);
	}

	private function subject( string $key, bool $hasBank = true ): SubjectDTO {
		return new SubjectDTO( key: $key, name: $key, archived: false, hasBank: $hasBank );
	}

	private function work( int $id, array $itemIds ): WorkDTO {
		return new WorkDTO(
			id: $id, subjectKey: 'inf', title: 'Work ' . $id, workType: WorkType::Practice,
			itemIds: $itemIds, instructions: '', authorId: 0, status: 'publish',
		);
	}

	private function assessment( int $id, array $taskIds, array $taskPoints = array(), array $taskNumbers = array() ): AssessmentDTO {
		return new AssessmentDTO(
			id: $id, subjectKey: 'inf', title: 'Assessment ' . $id, taskIds: $taskIds,
			timeLimit: 0, attemptsAllowed: 0, passScore: 0.0, scoringPolicy: ScoringPolicy::Highest,
			status: 'publish', kind: AssessmentKind::Control, taskPoints: $taskPoints, scoreMap: array(),
			taskNumbers: $taskNumbers,
		);
	}

	public function test_plan_reference_updates_expands_work_item_ids(): void {
		$this->subjects->method( 'readAll' )->willReturn( array( $this->subject( 'inf' ) ) );
		$this->works->method( 'getBankBySubject' )->willReturn( array(
			$this->work( 500, array( 10, 300, 20 ) ),
		) );
		$this->assessments->method( 'getBankBySubject' )->willReturn( array() );

		$changes = $this->planner->planReferenceUpdates( array( 300 => array( 301, 302, 303 ) ) );

		self::assertCount( 1, $changes );
		self::assertSame( 'work', $changes[0]->kind );
		self::assertSame( array( 10, 301, 302, 303, 20 ), $changes[0]->new_item_ids );
	}

	public function test_plan_reference_updates_skips_untouched_work(): void {
		$this->subjects->method( 'readAll' )->willReturn( array( $this->subject( 'inf' ) ) );
		$this->works->method( 'getBankBySubject' )->willReturn( array(
			$this->work( 500, array( 10, 20 ) ),
		) );
		$this->assessments->method( 'getBankBySubject' )->willReturn( array() );

		$changes = $this->planner->planReferenceUpdates( array( 300 => array( 301, 302, 303 ) ) );

		self::assertSame( array(), $changes );
	}

	public function test_plan_reference_updates_assessment_points_default_to_one_per_child(): void {
		$this->subjects->method( 'readAll' )->willReturn( array( $this->subject( 'inf' ) ) );
		$this->works->method( 'getBankBySubject' )->willReturn( array() );
		$this->assessments->method( 'getBankBySubject' )->willReturn( array(
			$this->assessment( 600, array( 300 ), array( 300 => 3.0 ) ),
		) );

		$changes = $this->planner->planReferenceUpdates( array( 300 => array( 301, 302, 303 ) ) );

		self::assertCount( 1, $changes );
		self::assertSame(
			array( 301 => 1.0, 302 => 1.0, 303 => 1.0 ),
			$changes[0]->new_task_points
		);
	}

	/**
	 * Регрессия: taskNumbers не должен засоряться номерами связок, которых
	 * нет в taskIds ЭТОЙ конкретной работы/контрольной (даже если они есть
	 * где-то ещё в общем плане материализации).
	 */
	public function test_plan_reference_updates_scopes_task_numbers_to_relevant_bundle_only(): void {
		$this->subjects->method( 'readAll' )->willReturn( array( $this->subject( 'inf' ) ) );
		$this->works->method( 'getBankBySubject' )->willReturn( array() );
		$this->assessments->method( 'getBankBySubject' )->willReturn( array(
			// Ссылается только на связку 300 — связки 400 в этой контрольной нет.
			$this->assessment( 600, array( 300 ) ),
		) );

		$childOf300 = new \WP_Post( array( 'ID' => 301, 'post_type' => 'fs_lms_problems' ) );
		$this->posts->method( 'get' )->willReturnMap( array(
			array( 301, $childOf300 ),
		) );

		$changes = $this->planner->planReferenceUpdates( array(
			300 => array( 301 ),
			400 => array( 401 ),
		) );

		self::assertCount( 1, $changes );
		self::assertArrayHasKey( 301, $changes[0]->new_task_numbers );
		self::assertArrayNotHasKey( 401, $changes[0]->new_task_numbers );
	}

	public function test_apply_reference_updates_separates_applied_and_failed(): void {
		$this->subjects->method( 'readAll' )->willReturn( array( $this->subject( 'inf' ) ) );
		$this->works->method( 'getBankBySubject' )->willReturn( array(
			$this->work( 500, array( 300 ) ),
			$this->work( 501, array( 300 ) ),
		) );
		$this->assessments->method( 'getBankBySubject' )->willReturn( array() );

		$changes = $this->planner->planReferenceUpdates( array( 300 => array( 301, 302, 303 ) ) );
		self::assertCount( 2, $changes );

		$this->works->method( 'setItemIds' )->willReturnMap( array(
			array( 500, array( 301, 302, 303 ), true ),
			array( 501, array( 301, 302, 303 ), false ),
		) );

		$result = $this->planner->applyReferenceUpdates( $changes );

		self::assertCount( 1, $result['applied'] );
		self::assertCount( 1, $result['failed'] );
		self::assertSame( 500, $result['applied'][0]->post_id );
		self::assertSame( 501, $result['failed'][0]->post_id );
	}

	/**
	 * Регрессия: {@see \Inc\Registrars\SubjectContentRegistrar::registerAll()} —
	 * Work/Assessment CPT регистрируются независимо от hasBank, а tasks CPT — нет.
	 * findBundleParents() ищет parent-посты только там, где вообще есть tasks CPT
	 * (hasBank) + глобальный банк fs_lms_problems.
	 */
	public function test_find_bundle_parents_uses_only_subjects_with_bank_plus_global_pool(): void {
		$this->subjects->method( 'readAll' )->willReturn( array(
			$this->subject( 'inf', true ),
			$this->subject( 'git', false ),
		) );

		$capturedArgs = null;
		$this->posts->method( 'query' )
			->willReturnCallback( function ( string $postType, array $args ) use ( &$capturedArgs ) {
				$capturedArgs = $args;
				return array( 'posts' => array( 301, 302 ), 'total' => 2 );
			} );

		$ids = $this->planner->findBundleParents();

		self::assertSame( array( 301, 302 ), $ids );
		self::assertSame( array( 'inf_tasks', 'fs_lms_problems' ), $capturedArgs['post_type'] );
	}

	public function test_materialize_delegates_to_task_bundle_service(): void {
		$this->bundles->expects( self::exactly( 2 ) )
			->method( 'syncChildren' )
			->willReturnMap( array(
				array( 300, array( 301, 302, 303 ) ),
				array( 400, array( 401, 402, 403 ) ),
			) );

		$summary = $this->planner->materialize( array( 300, 400 ) );

		self::assertSame(
			array( 300 => array( 301, 302, 303 ), 400 => array( 401, 402, 403 ) ),
			$summary
		);
	}
}
