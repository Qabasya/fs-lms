<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use FakeWpdb;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\AssessmentManager;
use Inc\Managers\CourseManager;
use Inc\Managers\LessonManager;
use Inc\Managers\PostManager;
use Inc\Managers\WorkManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Services\Course\ContentCloneService;
use PHPUnit\Framework\TestCase;

class ContentCloneServiceTest extends TestCase {

	private PostManager           $posts;
	private LessonManager         $lessons;
	private WorkManager           $works;
	private CourseManager         $courses;
	private AssessmentManager     $assessments;
	private FakeWpdb              $wpdb;
	private GroupLessonRepository $groupLessons;
	private ContentCloneService   $service;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_posts();

		$this->posts        = new PostManager();
		$this->lessons      = new LessonManager( $this->posts );
		$this->works        = new WorkManager( $this->posts );
		$this->courses      = new CourseManager( $this->posts );
		$this->assessments  = new AssessmentManager( $this->posts );
		$this->wpdb         = new FakeWpdb();
		$this->groupLessons = new GroupLessonRepository( $this->wpdb );

		$this->service = new ContentCloneService(
			$this->posts,
			$this->lessons,
			$this->works,
			$this->courses,
			$this->assessments,
			$this->groupLessons,
		);
	}

	// ── cloneLesson ─────────────────────────────────────────────────────────

	public function test_clone_lesson_returns_zero_for_missing(): void {
		$result = $this->service->cloneLesson( 999 );
		self::assertSame( 0, $result );
	}

	public function test_clone_lesson_creates_new_post_with_copy_suffix(): void {
		fs_test_seed_post(
			array( 'ID' => 1, 'post_type' => 'inf_lessons', 'post_title' => 'Введение', 'post_status' => 'publish' ),
			array( PostMetaName::Meta->value => array( 'steps' => array() ) )
		);

		$newId = $this->service->cloneLesson( 1 );

		self::assertGreaterThan( 0, $newId );
		self::assertNotSame( 1, $newId );

		$cloned = $this->lessons->get( $newId );
		self::assertNotNull( $cloned );
		self::assertSame( 'Введение (копия)', $cloned->topic );
		self::assertSame( 'draft', $cloned->status );
	}

	public function test_clone_lesson_copies_steps(): void {
		$steps = array(
			array( 'key' => 's1', 'type' => 'text', 'payload' => array( 'content' => 'Hello' ) ),
			array( 'key' => 's2', 'type' => 'video', 'payload' => array( 'url' => 'https://example.com' ) ),
		);
		fs_test_seed_post(
			array( 'ID' => 2, 'post_type' => 'inf_lessons', 'post_title' => 'Урок', 'post_status' => 'publish' ),
			array( PostMetaName::Meta->value => array( 'steps' => $steps ) )
		);

		$newId  = $this->service->cloneLesson( 2 );
		$cloned = $this->lessons->get( $newId );

		self::assertCount( 2, $cloned->steps );
		self::assertSame( 's1', $cloned->steps[0]->key );
		self::assertSame( 's2', $cloned->steps[1]->key );
	}

	// ── cloneWork ───────────────────────────────────────────────────────────

	public function test_clone_work_returns_zero_for_missing(): void {
		self::assertSame( 0, $this->service->cloneWork( 999 ) );
	}

	public function test_clone_work_creates_copy_with_same_items(): void {
		fs_test_seed_post(
			array( 'ID' => 10, 'post_type' => 'inf_works', 'post_title' => 'ДЗ', 'post_status' => 'publish', 'post_content' => 'Инструкция' ),
			array( PostMetaName::Meta->value => array( 'work_type' => 'homework', 'item_ids' => array( 5, 6 ) ) )
		);

		$newId = $this->service->cloneWork( 10 );

		self::assertGreaterThan( 0, $newId );
		$cloned = $this->works->get( $newId );
		self::assertNotNull( $cloned );
		self::assertSame( 'ДЗ (копия)', $cloned->title );
		self::assertSame( array( 5, 6 ), $cloned->itemIds );
		self::assertSame( 'draft', $cloned->status );
	}

	// ── cloneAssessment ─────────────────────────────────────────────────────

	public function test_clone_assessment_returns_zero_for_missing(): void {
		self::assertSame( 0, $this->service->cloneAssessment( 999 ) );
	}

	public function test_clone_assessment_copies_settings(): void {
		fs_test_seed_post(
			array( 'ID' => 20, 'post_type' => 'inf_assessments', 'post_title' => 'Контрольная', 'post_status' => 'publish' ),
			array( PostMetaName::Meta->value => array(
				'task_ids'           => array( 7, 8, 9 ),
				'time_limit_minutes' => 60,
				'max_attempts'       => 3,
				'pass_score'         => 70.0,
				'shuffle'            => true,
				'scoring_policy'     => 'highest',
			) )
		);

		$newId = $this->service->cloneAssessment( 20 );

		self::assertGreaterThan( 0, $newId );
		$meta = $GLOBALS['_fs_test_meta'][ $newId ][ PostMetaName::Meta->value ];
		self::assertSame( array( 7, 8, 9 ), $meta['task_ids'] );
		self::assertSame( 60, $meta['time_limit_minutes'] );
		self::assertSame( 'highest', $meta['scoring_policy'] );
	}

	// ── cloneCourse ─────────────────────────────────────────────────────────

	public function test_clone_course_returns_zero_for_missing(): void {
		self::assertSame( 0, $this->service->cloneCourse( 999 ) );
	}

	public function test_clone_course_shallow_reuses_lesson_refs(): void {
		fs_test_seed_post(
			array( 'ID' => 100, 'post_type' => 'inf_courses', 'post_title' => 'Курс', 'post_status' => 'publish' ),
			array( PostMetaName::Meta->value => array( 'modules' => array(
				array( 'id' => 'm1', 'title' => 'Модуль 1', 'lesson_ids' => array( 1, 2 ) ),
			) ) )
		);

		$newId = $this->service->cloneCourse( 100, 'shallow' );

		self::assertGreaterThan( 0, $newId );
		$cloned = $this->courses->get( $newId );
		self::assertSame( 'Курс (копия)', $cloned->title );
		self::assertCount( 1, $cloned->modules );
		self::assertSame( array( 1, 2 ), $cloned->modules[0]->lessonIds );
	}

	public function test_clone_course_deep_forks_lessons(): void {
		// Seed lessons that will be cloned.
		foreach ( array( 1, 2 ) as $id ) {
			fs_test_seed_post(
				array( 'ID' => $id, 'post_type' => 'inf_lessons', 'post_title' => "Урок $id", 'post_status' => 'publish' ),
				array( PostMetaName::Meta->value => array( 'steps' => array() ) )
			);
		}
		fs_test_seed_post(
			array( 'ID' => 100, 'post_type' => 'inf_courses', 'post_title' => 'Курс', 'post_status' => 'publish' ),
			array( PostMetaName::Meta->value => array( 'modules' => array(
				array( 'id' => 'm1', 'title' => 'Модуль 1', 'lesson_ids' => array( 1, 2 ) ),
			) ) )
		);

		$newId  = $this->service->cloneCourse( 100, 'deep' );
		$cloned = $this->courses->get( $newId );

		self::assertCount( 1, $cloned->modules );
		$newLessonIds = $cloned->modules[0]->lessonIds;
		self::assertCount( 2, $newLessonIds );
		// Deep clone creates new IDs — they must differ from originals.
		self::assertNotContains( 1, $newLessonIds );
		self::assertNotContains( 2, $newLessonIds );
	}

	// ── forkLessonForGroup ──────────────────────────────────────────────────

	private function queueGroupLessonRow( int $id, int $groupId, int $lessonId ): void {
		$this->wpdb->queueRow( array(
			'id'                => $id,
			'group_id'          => $groupId,
			'lesson_id'         => $lessonId,
			'position'          => 1,
			'work_ids_snapshot' => null,
			'extra_work_ids'    => '[]',
			'scheduled_at'      => null,
			'teacher_user_id'   => null,
			'visibility'        => 'open',
			'opened_at'         => null,
			'homework_due_at'   => null,
			'allow_late'        => 1,
			'recording_url'     => null,
			'created_by_user_id' => null,
			'updated_by_user_id' => null,
		) );
	}

	public function test_fork_lesson_for_group_returns_zero_for_wrong_group(): void {
		$this->queueGroupLessonRow( 5, groupId: 10, lessonId: 1 );

		$result = $this->service->forkLessonForGroup( groupId: 99, groupLessonId: 5 );

		self::assertSame( 0, $result );
	}

	public function test_fork_lesson_for_group_creates_fork_with_meta(): void {
		fs_test_seed_post(
			array( 'ID' => 1, 'post_type' => 'inf_lessons', 'post_title' => 'Урок', 'post_status' => 'publish' ),
			array( PostMetaName::Meta->value => array( 'steps' => array() ) )
		);
		$this->queueGroupLessonRow( id: 5, groupId: 10, lessonId: 1 );

		$forkId = $this->service->forkLessonForGroup( groupId: 10, groupLessonId: 5 );

		self::assertGreaterThan( 0, $forkId );
		self::assertNotSame( 1, $forkId );

		// forked_from meta set on fork.
		$forkedFrom = (int) ( $GLOBALS['_fs_test_meta'][ $forkId ][ PostMetaName::ForkedFrom->value ] ?? 0 );
		self::assertSame( 1, $forkedFrom );

		// forked_for_group meta set on fork.
		$forkedFor = (int) ( $GLOBALS['_fs_test_meta'][ $forkId ][ PostMetaName::ForkedForGroup->value ] ?? 0 );
		self::assertSame( 10, $forkedFor );

		// group_lessons.lesson_id updated to fork.
		$update = $this->wpdb->updates[0] ?? null;
		self::assertNotNull( $update );
		self::assertSame( array( 'lesson_id' => $forkId ), $update['data'] );
		self::assertSame( array( 'id' => 5 ), $update['where'] );
	}

	public function test_fork_lesson_for_group_is_idempotent(): void {
		// Lesson already forked for this group.
		fs_test_seed_post(
			array( 'ID' => 1, 'post_type' => 'inf_lessons', 'post_title' => 'Форк', 'post_status' => 'publish' ),
			array(
				PostMetaName::Meta->value         => array( 'steps' => array() ),
				PostMetaName::ForkedForGroup->value => 10,
			)
		);
		// group_lessons already points to the fork (lesson_id = 1).
		$this->queueGroupLessonRow( id: 5, groupId: 10, lessonId: 1 );

		$result = $this->service->forkLessonForGroup( groupId: 10, groupLessonId: 5 );

		// Returns existing lesson ID without creating a new post.
		self::assertSame( 1, $result );
		self::assertEmpty( $this->wpdb->updates );
	}

	public function test_fork_lesson_for_group_returns_zero_when_lesson_missing(): void {
		// group_lesson exists but lesson post is gone.
		$this->queueGroupLessonRow( id: 5, groupId: 10, lessonId: 999 );

		$result = $this->service->forkLessonForGroup( groupId: 10, groupLessonId: 5 );

		self::assertSame( 0, $result );
	}
}
