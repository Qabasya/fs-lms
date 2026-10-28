<?php

declare( strict_types=1 );

namespace Unit\Services\Profile\Learner;

use Inc\DTO\Assessment\AttemptDTO;
use Inc\DTO\Course\CourseDTO;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Managers\Course\CourseManager;
use Inc\Managers\Course\LessonManager;
use Inc\Services\Assessment\ExamLockService;
use Inc\Services\Course\LessonProgressService;
use Inc\Services\Profile\Learner\LearnerContextBuilder;
use Inc\Services\Profile\Learner\LearnerCoursesSection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Баннер «идёт контрольная/экзамен» кабинета: ссылка экзаменной попытки ведёт на станцию по записи, а не на страницу
 * работы курса (по пути курса станция экзамена открылась бы 404).
 */
#[AllowMockObjectsWithoutExpectations]
class LearnerCoursesSectionTest extends TestCase {

	private ExamLockService&MockObject $lock;
	private CourseManager&MockObject $courses;
	private AssessmentManager&MockObject $assessments;
	private LearnerCoursesSection $section;

	protected function setUp(): void {
		parent::setUp();

		$this->lock        = $this->createMock( ExamLockService::class );
		$this->assessments = $this->createMock( AssessmentManager::class );
		$this->assessments->method( 'examStationUrl' )->willReturnCallback(
			static fn ( int $assessmentId, int $registrationId ): string => "https://site.test/exam-{$assessmentId}/?exam_reg={$registrationId}"
		);

		$this->courses = $this->createMock( CourseManager::class );
		$this->section = new LearnerCoursesSection(
			$this->courses,
			$this->createMock( LessonManager::class ),
			$this->createMock( LessonProgressService::class ),
			$this->lock,
			$this->createMock( LearnerContextBuilder::class ),
			$this->assessments,
		);
	}

	/** @param array<string, mixed> $override */
	private function attempt( array $override = array() ): AttemptDTO {
		return AttemptDTO::fromArray( array_merge( array(
			'id' => 9, 'assessment_id' => 50, 'student_person_id' => 10, 'group_id' => null, 'attempt_number' => 1,
			'status' => 'in_progress', 'started_at' => '2026-03-12 10:00:00', 'deadline_at' => '2026-03-12 13:55:00',
		), $override ) );
	}

	public function test_no_lock_gives_null(): void {
		$this->lock->method( 'getActiveLockingAttempt' )->willReturn( null );

		self::assertNull( $this->section->examLock( 10 ) );
	}

	public function test_exam_lock_url_contains_exam_registration(): void {
		$this->lock->method( 'getActiveLockingAttempt' )->willReturn( $this->attempt( array( 'exam_participation_id' => 7, 'exam_registration_id' => 6 ) ) );

		$banner = $this->section->examLock( 10 );

		self::assertSame( 'https://site.test/exam-50/?exam_reg=6', $banner['url'] );
		self::assertTrue( $banner['is_exam'], 'Для официального экзамена баннер пишет «экзамен», а не «контрольная».' );
	}

	public function test_course_control_lock_links_to_the_assessment_page(): void {
		$this->lock->method( 'getActiveLockingAttempt' )->willReturn( $this->attempt( array( 'group_id' => 5 ) ) );

		$banner = $this->section->examLock( 10 );

		self::assertStringNotContainsString( 'exam_reg', $banner['url'] );
		self::assertFalse( $banner['is_exam'] );
	}

	/** Проведённое занятие не должно теряться за копией той же темы в пуле: у копии нет ни даты, ни ссылки в плеер. */
	public function test_held_row_wins_over_pool_copy_of_the_same_lesson(): void {
		$this->courses->method( 'get' )->willReturn( CourseDTO::fromArray( array(
			'id' => 1,
			'modules' => array( array( 'id' => 'm1', 'title' => 'Модуль', 'lesson_ids' => array( 3744 ) ) ),
		) ) );

		$row = static fn ( int $id, ?string $at ): GroupLessonDTO => GroupLessonDTO::fromArray( array(
			'id' => $id, 'group_id' => 8, 'lesson_id' => 3744, 'position' => $id, 'scheduled_at' => $at,
		) );
		$item = static fn ( int $id, string $date ): array => array(
			'topic' => 'Тема', 'date' => $date, 'room' => '', 'status' => '' === $date ? 'done' : 'done',
			'player_url' => '' === $date ? '' : "https://site.test/lesson/?gl={$id}",
		);

		$courses = $this->section->buildCourses(
			array( 8 => array( 'course_id' => 1, 'room_id' => 0, 'teacher_id' => 0, 'name' => 'КЕГЭ-4', 'subject' => '', 'subject_key' => '' ) ),
			array( 101 => $row( 101, '2026-10-20 18:00:00' ), 250 => $row( 250, null ) ),
			array( 101 => $item( 101, '2026-10-20' ), 250 => $item( 250, '' ) ),
			array(),
			10
		);

		$lesson = $courses[0]['modules'][0]['lessons'][0];
		self::assertSame( 'https://site.test/lesson/?gl=101', $lesson['player_url'] );
		self::assertSame( '2026-10-20', $lesson['date'] );
	}
}
