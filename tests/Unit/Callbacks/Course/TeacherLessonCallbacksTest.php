<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Course;

use FakeWpdb;
use Inc\Callbacks\Course\TeacherLessonCallbacks;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Managers\Course\CourseManager;
use Inc\Managers\Course\LessonManager;
use Inc\Managers\Course\WorkManager;
use Inc\Managers\Wp\PostManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Services\Assessment\TaskPreviewService;
use Inc\Services\Course\ContentCloneService;
use Inc\Services\Course\GroupAccessGuard;
use Inc\Services\Course\LessonAuthoringService;
use Inc\Services\Course\LessonVisibilityService;
use Inc\Services\Template\TemplateRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Покрытие copy-on-write форка урока занятия для преподавателя (Этап 3):
 * первая правка форкает мастер, повторные пишут в тот же форк, чужая группа
 * и лимит шагов отклоняются без побочных эффектов.
 */
class TeacherLessonCallbacksTest extends TestCase {

	private TeacherLessonCallbacks $callbacks;
	private LessonManager          $lessons;
	private GroupLessonRepository  $groupLessons;
	private FakeWpdb               $wpdb;

	/** @var GroupAccessGuard&\PHPUnit\Framework\MockObject\MockObject */
	private $guard;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_posts();
		fs_test_reset_ajax();

		$posts              = new PostManager();
		$this->lessons      = new LessonManager( $posts );
		$this->wpdb         = new FakeWpdb();
		$this->groupLessons = new GroupLessonRepository( $this->wpdb );
		$this->guard        = $this->createMock( GroupAccessGuard::class );

		$cloneService = new ContentCloneService(
			$posts,
			$this->lessons,
			new WorkManager( $posts ),
			new CourseManager( $posts ),
			new AssessmentManager( $posts ),
			$this->groupLessons,
		);

		$this->callbacks = new TeacherLessonCallbacks(
			$this->groupLessons,
			$this->guard,
			$this->lessons,
			$cloneService,
			new LessonAuthoringService( $posts, $this->lessons, new TemplateRegistry() ),
			$this->createMock( LessonVisibilityService::class ),
			$posts,
			$this->createMock( TaskPreviewService::class ),
		);
	}

	/** group_lessons row для FakeWpdb::queueRow(); каждый find() съедает одну из очереди. */
	private function queueGroupLessonRow( int $id, int $groupId, int $lessonId ): void {
		$this->wpdb->queueRow( array(
			'id'                 => $id,
			'group_id'           => $groupId,
			'lesson_id'          => $lessonId,
			'position'           => 1,
			'work_ids_snapshot'  => null,
			'extra_work_ids'     => '[]',
			'scheduled_at'       => null,
			'teacher_user_id'    => null,
			'visibility'         => 'open',
			'opened_at'          => null,
			'homework_due_at'    => null,
			'allow_late'         => 1,
			'recording_url'      => null,
			'created_by_user_id' => null,
			'updated_by_user_id' => null,
		) );
	}

	private function seedLesson( int $id, string $subject, array $stepKeys = array( 's1' ), array $extraMeta = array() ): void {
		$steps = array_map(
			static fn ( string $k ): array => array( 'key' => $k, 'type' => 'text', 'payload' => array( 'title' => $k ) ),
			$stepKeys
		);
		fs_test_seed_post(
			array( 'ID' => $id, 'post_type' => $subject . '_lessons', 'post_title' => 'Урок ' . $id, 'post_status' => 'publish' ),
			array( PostMetaName::Meta->value => array( 'steps' => $steps ) ) + $extraMeta
		);
	}

	public function test_save_forks_master_on_first_save(): void {
		$this->seedLesson( 1, 'inf' );
		// Первый find() — собственная guard-проверка коллбека; второй — внутри forkLessonForGroup.
		$this->queueGroupLessonRow( 5, groupId: 10, lessonId: 1 );
		$this->queueGroupLessonRow( 5, groupId: 10, lessonId: 1 );
		$this->guard->method( 'canManage' )->willReturn( true );

		$_POST = array(
			'group_lesson_id' => '5',
			'steps'           => array(
				array( 'key' => 's1', 'type' => 'text', 'payload' => array( 'title' => 'A', 'content' => 'B' ) ),
			),
		);

		$r = fs_test_capture_json( fn() => $this->callbacks->ajaxSaveGroupLessonSteps() );

		self::assertTrue( $r->success );
		self::assertTrue( $r->payload['forked'] );

		self::assertCount( 1, $this->wpdb->updates );
		$forkId = (int) $this->wpdb->updates[0]['data']['lesson_id'];
		self::assertGreaterThan( 0, $forkId );
		self::assertNotSame( 1, $forkId );

		// Мастер не тронут, форк помечен ForkedForGroup и получил новые шаги.
		self::assertSame( array( 's1' ), array_map( static fn ( $s ) => $s->key, $this->lessons->get( 1 )->steps ) );
		self::assertSame( 10, (int) ( $GLOBALS['_fs_test_meta'][ $forkId ][ PostMetaName::ForkedForGroup->value ] ?? 0 ) );
		self::assertSame( 'A', $this->lessons->get( $forkId )->steps[0]->payload['title'] );
	}

	public function test_save_reuses_existing_fork_on_second_save(): void {
		// Урок уже форк этой группы; group_lessons уже перецеплен на него (lesson_id=1).
		$this->seedLesson( 1, 'inf', array( 's1' ), array( PostMetaName::ForkedForGroup->value => 10 ) );
		$this->queueGroupLessonRow( 5, groupId: 10, lessonId: 1 );
		$this->queueGroupLessonRow( 5, groupId: 10, lessonId: 1 );
		$this->guard->method( 'canManage' )->willReturn( true );

		$_POST = array(
			'group_lesson_id' => '5',
			'steps'           => array(
				array( 'key' => 's1', 'type' => 'text', 'payload' => array( 'title' => 'A2', 'content' => 'B2' ) ),
			),
		);

		$r = fs_test_capture_json( fn() => $this->callbacks->ajaxSaveGroupLessonSteps() );

		self::assertTrue( $r->success );
		self::assertEmpty( $this->wpdb->updates ); // повторного форка/перецепления не было
		self::assertSame( 'A2', $this->lessons->get( 1 )->steps[0]->payload['title'] );
	}

	public function test_save_denies_when_cannot_manage_group(): void {
		$this->seedLesson( 1, 'inf' );
		// Guard блокирует до вызова forkLessonForGroup — второй find() не понадобится.
		$this->queueGroupLessonRow( 5, groupId: 10, lessonId: 1 );
		$this->guard->method( 'canManage' )->willReturn( false );

		$_POST = array(
			'group_lesson_id' => '5',
			'steps'           => array( array( 'key' => 's1', 'type' => 'text', 'payload' => array( 'title' => 'X' ) ) ),
		);

		$r = fs_test_capture_json( fn() => $this->callbacks->ajaxSaveGroupLessonSteps() );

		self::assertFalse( $r->success );
		self::assertEmpty( $this->wpdb->updates );
		self::assertSame( array( 's1' ), array_map( static fn ( $s ) => $s->key, $this->lessons->get( 1 )->steps ) );
	}

	public function test_save_rejects_more_than_max_steps(): void {
		$this->seedLesson( 1, 'inf', array( 's1' ), array( PostMetaName::ForkedForGroup->value => 10 ) );
		$this->queueGroupLessonRow( 5, groupId: 10, lessonId: 1 );
		$this->queueGroupLessonRow( 5, groupId: 10, lessonId: 1 );
		$this->guard->method( 'canManage' )->willReturn( true );

		$steps = array();
		for ( $i = 1; $i <= 21; $i++ ) {
			$steps[] = array( 'key' => 's' . $i, 'type' => 'text', 'payload' => array( 'title' => 'T' . $i ) );
		}
		$_POST = array( 'group_lesson_id' => '5', 'steps' => $steps );

		$r = fs_test_capture_json( fn() => $this->callbacks->ajaxSaveGroupLessonSteps() );

		self::assertFalse( $r->success );
		self::assertCount( 1, $this->lessons->get( 1 )->steps ); // урок не перезаписан
	}
}
