<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\DTO\Course\CourseDTO;
use Inc\DTO\Course\LessonDTO;
use Inc\DTO\Course\WorkDTO;
use Inc\Enums\Course\WorkType;
use Inc\Enums\Subject\TaskTemplate;
use Inc\Managers\Course\LessonManager;
use Inc\Managers\Course\WorkManager;
use Inc\Managers\Wp\PostManager;
use Inc\Services\Course\OpenCourseValidator;
use Inc\Services\Task\TaskCheckerRegistry;
use Inc\Services\Template\TemplateResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Автопроверяемость контента для открытых групп: курс целиком ({@see OpenCourseValidator::problems()}) и один урок
 * ({@see OpenCourseValidator::lessonProblems()}). Урок вне курса показывает та же проверка, что и курс, — тексты совпадают.
 */
class OpenCourseValidatorTest extends TestCase {

	private LessonManager&MockObject $lessons;
	private WorkManager&MockObject $works;
	private PostManager&MockObject $posts;
	private TemplateResolver&MockObject $templates;
	private TaskCheckerRegistry&MockObject $checkers;
	private OpenCourseValidator $validator;

	protected function setUp(): void {
		parent::setUp();
		$this->lessons   = $this->createMock( LessonManager::class );
		$this->works     = $this->createMock( WorkManager::class );
		$this->posts     = $this->createMock( PostManager::class );
		$this->templates = $this->createMock( TemplateResolver::class );
		$this->checkers  = $this->createMock( TaskCheckerRegistry::class );

		// Задачи 100–199 автопроверяются (шаблон «стандартная»), остальные — нет (шаблон «файл»).
		$this->posts->method( 'get' )->willReturnCallback( static function ( int $id ): \WP_Post {
			$post     = new \WP_Post();
			$post->ID = $id;

			return $post;
		} );
		$this->templates->method( 'resolveEnum' )->willReturnCallback(
			static fn( \WP_Post $p ): TaskTemplate => $p->ID >= 100 && $p->ID < 200 ? TaskTemplate::Standard : TaskTemplate::File
		);
		$this->checkers->method( 'has' )->willReturnCallback( static fn( TaskTemplate $t ): bool => TaskTemplate::Standard === $t );

		$this->validator = new OpenCourseValidator( $this->lessons, $this->works, $this->posts, $this->templates, $this->checkers );
	}

	/** @param list<array{type: string, ref: int}> $steps */
	private function lesson( int $id, string $topic, array $steps ): LessonDTO {
		return LessonDTO::fromArray( array(
			'id'          => $id,
			'subject_key' => 'inf',
			'topic'       => $topic,
			'author_id'   => 0,
			'status'      => 'publish',
			'steps'       => array_map(
				static fn( array $s, int $i ): array => array( 'key' => 's' . $i, 'type' => $s['type'], 'payload' => array( 'ref' => $s['ref'] ) ),
				$steps,
				array_keys( $steps )
			),
		) );
	}

	private function work( int $id, array $itemIds ): WorkDTO {
		return new WorkDTO( $id, 'inf', 'Работа ' . $id, WorkType::Practice, $itemIds, '', 1, 'publish' );
	}

	public function test_lesson_with_only_auto_checked_tasks_has_no_problems(): void {
		$lesson = $this->lesson( 1, 'Циклы', array( array( 'type' => 'task', 'ref' => 101 ), array( 'type' => 'task', 'ref' => 102 ) ) );

		self::assertSame( array(), $this->validator->lessonProblems( $lesson ) );
	}

	public function test_lesson_with_a_manual_task_is_reported_with_the_count(): void {
		$lesson = $this->lesson( 1, 'Циклы', array( array( 'type' => 'task', 'ref' => 101 ), array( 'type' => 'task', 'ref' => 300 ), array( 'type' => 'task', 'ref' => 301 ) ) );

		self::assertSame( array( '«Циклы»: задач без автопроверки — 2' ), $this->validator->lessonProblems( $lesson ) );
	}

	public function test_lesson_with_an_assessment_is_reported(): void {
		$lesson = $this->lesson( 1, 'Контроль', array( array( 'type' => 'assessment', 'ref' => 7 ) ) );

		self::assertSame( array( '«Контроль»: контрольные недоступны в открытом курсе' ), $this->validator->lessonProblems( $lesson ) );
	}

	public function test_tasks_inside_a_work_are_checked_too_and_counted_once(): void {
		$this->works->method( 'get' )->willReturn( $this->work( 9, array( 101, 300, 300 ) ) );
		// 300 и в работе, и отдельным шагом: считается один раз.
		$lesson = $this->lesson( 1, 'Работа', array( array( 'type' => 'work', 'ref' => 9 ), array( 'type' => 'task', 'ref' => 300 ) ) );

		self::assertSame( array( '«Работа»: задач без автопроверки — 1' ), $this->validator->lessonProblems( $lesson ) );
	}

	public function test_a_missing_work_does_not_break_the_check(): void {
		$this->works->method( 'get' )->willReturn( null );
		$lesson = $this->lesson( 1, 'Работа', array( array( 'type' => 'work', 'ref' => 9 ) ) );

		self::assertSame( array(), $this->validator->lessonProblems( $lesson ) );
	}

	public function test_both_problems_of_one_lesson_come_in_a_stable_order(): void {
		$lesson = $this->lesson( 1, 'Смесь', array( array( 'type' => 'task', 'ref' => 300 ), array( 'type' => 'assessment', 'ref' => 7 ) ) );

		self::assertSame(
			array( '«Смесь»: контрольные недоступны в открытом курсе', '«Смесь»: задач без автопроверки — 1' ),
			$this->validator->lessonProblems( $lesson )
		);
	}

	public function test_course_problems_are_the_lesson_problems_in_course_order_and_skip_missing_lessons(): void {
		$first  = $this->lesson( 1, 'Первый', array( array( 'type' => 'task', 'ref' => 300 ) ) );
		$second = $this->lesson( 2, 'Второй', array( array( 'type' => 'assessment', 'ref' => 7 ) ) );
		$this->lessons->method( 'get' )->willReturnCallback( static fn( int $id ): ?LessonDTO => array( 1 => $first, 2 => $second )[ $id ] ?? null );
		$course = $this->createStub( CourseDTO::class );
		$course->method( 'lessonIds' )->willReturn( array( 1, 99, 2 ) );

		// Тексты и порядок — как до выделения lessonProblems(): урок 99 не найден и пропущен.
		self::assertSame(
			array( '«Первый»: задач без автопроверки — 1', '«Второй»: контрольные недоступны в открытом курсе' ),
			$this->validator->problems( $course )
		);
	}

	public function test_assert_self_checkable_throws_with_all_problems_and_passes_a_clean_course(): void {
		$bad  = $this->lesson( 1, 'Плохой', array( array( 'type' => 'task', 'ref' => 300 ) ) );
		$good = $this->lesson( 2, 'Хороший', array( array( 'type' => 'task', 'ref' => 101 ) ) );
		$this->lessons->method( 'get' )->willReturnCallback( static fn( int $id ): ?LessonDTO => array( 1 => $bad, 2 => $good )[ $id ] ?? null );

		$clean = $this->createStub( CourseDTO::class );
		$clean->method( 'lessonIds' )->willReturn( array( 2 ) );
		$this->validator->assertSelfCheckable( $clean );

		$dirty = $this->createStub( CourseDTO::class );
		$dirty->method( 'lessonIds' )->willReturn( array( 1, 2 ) );
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( '«Плохой»: задач без автопроверки — 1' );
		$this->validator->assertSelfCheckable( $dirty );
	}
}
