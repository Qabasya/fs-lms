<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\DTO\Course\CourseDTO;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\LessonDTO;
use Inc\DTO\Course\ModuleDTO;
use Inc\Enums\Course\GateState;
use Inc\Managers\Course\CourseManager;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Course\CourseNavService;
use Inc\Services\Course\LessonGateResolver;
use Inc\Services\Course\LessonProgressService;
use PHPUnit\Framework\TestCase;

/**
 * Тесты навигационной read-модели плеера (Эпик 14): оболочка shell() (T14.2)
 * и дерево курса tree() (T14.3).
 */
class CourseNavServiceTest extends TestCase {

	private GroupsRepository        $groups;
	private GroupLessonRepository   $groupLessons;
	private CourseManager           $courses;
	private LessonManager           $lessons;
	private LessonProgressService   $progress;
	private LessonGateResolver      $gate;
	private StudentRecordRepository $records;
	private CourseNavService        $service;

	protected function setUp(): void {
		parent::setUp();
		$this->groups       = $this->createMock( GroupsRepository::class );
		$this->groupLessons = $this->createMock( GroupLessonRepository::class );
		$this->courses      = $this->createMock( CourseManager::class );
		$this->lessons      = $this->createMock( LessonManager::class );
		$this->progress     = $this->createMock( LessonProgressService::class );
		$this->gate         = $this->createMock( LessonGateResolver::class );
		$this->records      = $this->createMock( StudentRecordRepository::class );

		$this->service = new CourseNavService(
			$this->groups,
			$this->groupLessons,
			$this->courses,
			$this->lessons,
			$this->progress,
			$this->gate,
			$this->records,
		);
	}

	private function row( int $id, ?int $lessonId, string $kind = 'group', ?int $continuedFromId = null ): GroupLessonDTO {
		return new GroupLessonDTO(
			id: $id, groupId: 1, lessonId: $lessonId, position: $id, workIdsSnapshot: null, extraWorkIds: array(),
			scheduledAt: null, endsAt: null, isPinned: false, teacherUserId: null, visibility: 'open',
			openedAt: null, homeworkDueAt: null, allowLate: true, recordingUrl: null,
			createdByUserId: null, updatedByUserId: null, kind: $kind, continuedFromId: $continuedFromId,
		);
	}

	/**
	 * Программа: уроки 100/200 в модуле 1, урок 300 в модуле 2, урок 400 вне модулей.
	 * Дополнительно: индивидуальное занятие и строка-продолжение — не считаются.
	 */
	private function arrangeProgram(): void {
		$this->groups->method( 'findById' )->with( 1 )
			->willReturn( (object) array( 'id' => 1, 'name' => 'Г1', 'course_id' => 77 ) );

		$this->courses->method( 'get' )->with( 77 )->willReturn( new CourseDTO(
			id: 77, subjectKey: 'inf', title: 'Python с нуля', descriptionHtml: '',
			modules: array(
				new ModuleDTO( 'm1', 'Начала', array( 100, 200 ) ),
				new ModuleDTO( 'm2', 'Циклы', array( 300 ) ),
			),
			authorId: 1, status: 'publish',
		) );

		$this->groupLessons->method( 'listByGroup' )->with( 1 )->willReturn( array(
			$this->row( 1, 100 ),
			$this->row( 2, 200 ),
			$this->row( 3, 300 ),
			$this->row( 4, 400 ),                    // вне модулей курса → «Дополнительно»
			$this->row( 5, 100, 'individual' ),      // индивидуальное — не в программе
			$this->row( 6, 200, 'group', 2 ),        // продолжение темы (D14) — не считается
		) );

		$this->lessons->method( 'get' )->willReturnCallback(
			static fn( int $id ): LessonDTO => new LessonDTO(
				id: $id, subjectKey: 'inf', topic: "Тема {$id}", steps: array(), authorId: 1, status: 'publish'
			)
		);

		// Пройден только урок 100 (gl=1); текущий — gl=2; остальные закрыты.
		$this->progress->method( 'isLessonCompleted' )
			->willReturnCallback( static fn( int $p, int $glId ): bool => 1 === $glId );
		$this->gate->method( 'resolveLesson' )->willReturn( GateState::Locked );
	}

	/* ── shell() (T14.2) ─────────────────────────────────────────────────── */

	public function test_shell_returns_course_module_progress_and_student(): void {
		$this->arrangeProgram();
		$this->records->method( 'findByStudent' )->with( 9 )->willReturn( array(
			new \Inc\DTO\Enrollment\StudentRecordDTO(
				id: 1, studentPersonId: 9, parentPersonId: 0, groupId: 1,
				snapshotLastName: 'Морозов', snapshotFirstName: 'Иван', snapshotMiddleName: null,
				snapshotSchool: null, snapshotGrade: '9 А', contractNo: null, contractDate: null,
				orderNo: null, orderDate: null, status: \Inc\Enums\Enrollment\EnrollmentStatus::Active,
				enrolledAt: '2026-01-01', enrolledByUserId: null, expelledAt: null,
				expelledByUserId: null, expelReason: null,
				createdAt: '2026-01-01', updatedAt: '2026-01-01',
			),
		) );

		$shell = $this->service->shell( 9, $this->row( 2, 200 ) );

		self::assertSame( 'Python с нуля', $shell['course_title'] );
		self::assertStringContainsString( 'Модуль 1', $shell['module_label'] );
		// Прогресс: 4 зачётных урока (без individual и продолжения), пройден 1 → 25%.
		self::assertSame( array( 'percent' => 25, 'done' => 1, 'total' => 4 ), $shell['course_progress'] );
		self::assertSame( 'Морозов Иван', $shell['student_name'] );
		self::assertSame( 'Ученик · 9 А', $shell['student_role'] );
	}

	public function test_shell_next_lesson_carries_gate(): void {
		$this->arrangeProgram();
		$this->records->method( 'findByStudent' )->willReturn( array() );

		$shell = $this->service->shell( 9, $this->row( 2, 200 ) );

		// Следующий после gl=2 — gl=3; гейт замокан как Locked.
		self::assertSame( 3, $shell['next_lesson']['group_lesson_id'] );
		self::assertFalse( $shell['next_lesson']['available'] );
	}

	public function test_shell_next_lesson_null_for_last(): void {
		$this->arrangeProgram();
		$this->records->method( 'findByStudent' )->willReturn( array() );

		self::assertNull( $this->service->shell( 9, $this->row( 4, 400 ) )['next_lesson'] );
	}

	/* ── tree() (T14.3) ──────────────────────────────────────────────────── */

	public function test_tree_builds_modules_with_states_and_extra_bucket(): void {
		$this->arrangeProgram();

		$tree    = $this->service->tree( 9, $this->row( 2, 200 ) );
		$modules = $tree['modules'];

		self::assertCount( 3, $modules ); // 2 модуля курса + «Дополнительно»

		// Модуль 1 содержит текущий урок → current; уроки: done + current.
		self::assertSame( 1, $modules[0]['number'] );
		self::assertSame( 'current', $modules[0]['state'] );
		self::assertSame( 'done', $modules[0]['lessons'][0]['state'] );
		self::assertSame( 'current', $modules[0]['lessons'][1]['state'] );

		// Сквозная нумерация по программе.
		self::assertSame( 1, $modules[0]['lessons'][0]['number'] );
		self::assertSame( 2, $modules[0]['lessons'][1]['number'] );

		// Модуль 2 закрыт целиком.
		self::assertSame( 'locked', $modules[1]['state'] );

		// Внемодульный урок — в псевдо-модуле без номера.
		self::assertNull( $modules[2]['number'] );
		self::assertSame( 400, $this->lessonIdOf( $modules[2]['lessons'][0] ) );
	}

	public function test_tree_without_course_puts_all_in_extra(): void {
		$this->groups->method( 'findById' )->willReturn( (object) array( 'id' => 1, 'name' => 'Г1', 'course_id' => null ) );
		$this->groupLessons->method( 'listByGroup' )->willReturn( array( $this->row( 1, 100 ) ) );
		$this->lessons->method( 'get' )->willReturn( null );
		$this->progress->method( 'isLessonCompleted' )->willReturn( false );

		$tree = $this->service->tree( 9, $this->row( 1, 100 ) );

		self::assertCount( 1, $tree['modules'] );
		self::assertNull( $tree['modules'][0]['number'] );
	}

	/** Узел дерева хранит group_lesson_id; лестница к lessonId — через фикстуру (gl=4 → lesson 400). */
	private function lessonIdOf( array $node ): int {
		return 4 === $node['group_lesson_id'] ? 400 : 0;
	}
}
