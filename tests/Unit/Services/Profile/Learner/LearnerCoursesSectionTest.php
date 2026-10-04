<?php

declare( strict_types=1 );

namespace Unit\Services\Profile\Learner;

use Inc\DTO\Assessment\AttemptDTO;
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
	private AssessmentManager&MockObject $assessments;
	private LearnerCoursesSection $section;

	protected function setUp(): void {
		parent::setUp();

		$this->lock        = $this->createMock( ExamLockService::class );
		$this->assessments = $this->createMock( AssessmentManager::class );
		$this->assessments->method( 'examStationUrl' )->willReturnCallback(
			static fn ( int $assessmentId, int $registrationId ): string => "https://site.test/exam-{$assessmentId}/?exam_reg={$registrationId}"
		);

		$this->section = new LearnerCoursesSection(
			$this->createMock( CourseManager::class ),
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
}
