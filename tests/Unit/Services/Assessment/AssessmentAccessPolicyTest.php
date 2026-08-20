<?php

declare( strict_types=1 );

namespace Unit\Services\Assessment;

use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\LessonDTO;
use Inc\DTO\Enrollment\StudentRecordDTO;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Assessment\AssessmentAccessPolicy;
use Inc\Services\Course\CoursePreviewAccessGuard;
use Inc\Services\Course\LessonAccessPolicy;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Контрольная отдаётся публичным пермалинком — доступ закрывает AssessmentAccessPolicy.
 * Ученик вправе открыть контрольную, только если зачислен в группу, чей урок ссылается
 * на эту контрольную, и урок ему доступен (LessonAccessPolicy::canRead).
 */
class AssessmentAccessPolicyTest extends TestCase {

	private StudentRecordRepository&MockObject $records;
	private GroupLessonRepository&MockObject   $groupLessons;
	private LessonManager&MockObject           $lessons;
	private LessonAccessPolicy&MockObject      $lessonAccess;
	private GroupsRepository&MockObject        $groups;
	private CoursePreviewAccessGuard&MockObject $previewAccess;
	private AssessmentAccessPolicy             $policy;

	protected function setUp(): void {
		parent::setUp();
		$this->records       = $this->createMock( StudentRecordRepository::class );
		$this->groupLessons  = $this->createMock( GroupLessonRepository::class );
		$this->lessons       = $this->createMock( LessonManager::class );
		$this->lessonAccess  = $this->createMock( LessonAccessPolicy::class );
		$this->groups        = $this->createMock( GroupsRepository::class );
		$this->previewAccess = $this->createMock( CoursePreviewAccessGuard::class );
		$this->policy        = new AssessmentAccessPolicy(
			$this->records,
			$this->groupLessons,
			$this->lessons,
			$this->lessonAccess,
			$this->groups,
			$this->previewAccess,
		);
	}

	public function test_guest_or_invalid_ids_denied(): void {
		self::assertFalse( $this->policy->canAccess( 0, 5 ) );
		self::assertFalse( $this->policy->canAccess( 5, 0 ) );
	}

	public function test_no_enrollments_denied(): void {
		$this->records->method( 'findByStudent' )->willReturn( array() );
		self::assertFalse( $this->policy->canAccess( 5, 100 ) );
	}

	public function test_enrolled_lesson_references_assessment_and_readable_grants(): void {
		$this->records->method( 'findByStudent' )->willReturn( array( $this->record( 10 ) ) );
		$this->groupLessons->method( 'listByGroup' )->with( 10 )->willReturn( array( $this->groupLesson( 42, 7 ) ) );
		$this->lessons->method( 'get' )->with( 7 )->willReturn( $this->lessonWithAssessment( 100 ) );
		$this->lessonAccess->method( 'canRead' )->with( 5, 42 )->willReturn( true );

		self::assertTrue( $this->policy->canAccess( 5, 100 ) );
	}

	public function test_lesson_does_not_reference_assessment_denied(): void {
		$this->records->method( 'findByStudent' )->willReturn( array( $this->record( 10 ) ) );
		$this->groupLessons->method( 'listByGroup' )->willReturn( array( $this->groupLesson( 42, 7 ) ) );
		$this->lessons->method( 'get' )->willReturn( $this->lessonWithAssessment( 999 ) );
		$this->lessonAccess->expects( self::never() )->method( 'canRead' );

		self::assertFalse( $this->policy->canAccess( 5, 100 ) );
	}

	public function test_referenced_but_lesson_not_readable_denied(): void {
		$this->records->method( 'findByStudent' )->willReturn( array( $this->record( 10 ) ) );
		$this->groupLessons->method( 'listByGroup' )->willReturn( array( $this->groupLesson( 42, 7 ) ) );
		$this->lessons->method( 'get' )->willReturn( $this->lessonWithAssessment( 100 ) );
		$this->lessonAccess->method( 'canRead' )->willReturn( false );

		self::assertFalse( $this->policy->canAccess( 5, 100 ) );
	}

	// --- helpers ---

	private function record( int $groupId ): StudentRecordDTO {
		return StudentRecordDTO::fromArray( array(
			'id'                  => 1,
			'student_person_id'   => 5,
			'parent_person_id'    => 0,
			'group_id'            => $groupId,
			'snapshot_last_name'  => 'T',
			'snapshot_first_name' => 'U',
			'status'              => 'active',
			'enrolled_at'         => '2024-01-01 00:00:00',
			'expelled_at'         => null,
			'created_at'          => '2024-01-01 00:00:00',
			'updated_at'          => '2024-01-01 00:00:00',
		) );
	}

	/**
	 * Привязка попытки: политика и так нашла занятие — возвращаем его, чтобы
	 * попытка не осталась без `group_lesson_id` при заходе по прямой ссылке.
	 */
	public function test_returns_lesson_that_grants_access(): void {
		$this->records->method( 'findByStudent' )->willReturn( array( $this->record( 10 ) ) );
		$this->groupLessons->method( 'listByGroup' )->willReturn( array( $this->groupLesson( 42, 7 ) ) );
		$this->lessons->method( 'get' )->willReturn( $this->lessonWithAssessment( 100 ) );
		$this->lessonAccess->method( 'canRead' )->willReturn( true );

		self::assertSame( 42, $this->policy->resolveAccessibleLesson( 5, 100 )?->id );
	}

	/** Контрольная в нескольких занятиях — берём самое позднее по расписанию. */
	public function test_picks_latest_scheduled_lesson(): void {
		$this->records->method( 'findByStudent' )->willReturn( array( $this->record( 10 ) ) );
		$this->groupLessons->method( 'listByGroup' )->willReturn( array(
			$this->groupLesson( 41, 7, '2026-03-01 10:00:00' ),
			$this->groupLesson( 43, 7, '2026-09-01 10:00:00' ),
			$this->groupLesson( 42, 7, '2026-05-01 10:00:00' ),
		) );
		$this->lessons->method( 'get' )->willReturn( $this->lessonWithAssessment( 100 ) );
		$this->lessonAccess->method( 'canRead' )->willReturn( true );

		self::assertSame( 43, $this->policy->resolveAccessibleLesson( 5, 100 )?->id );
	}

	/** Занятие без даты уступает датированному. */
	public function test_dated_lesson_wins_over_undated(): void {
		$this->records->method( 'findByStudent' )->willReturn( array( $this->record( 10 ) ) );
		$this->groupLessons->method( 'listByGroup' )->willReturn( array(
			$this->groupLesson( 41, 7 ),
			$this->groupLesson( 42, 7, '2026-05-01 10:00:00' ),
		) );
		$this->lessons->method( 'get' )->willReturn( $this->lessonWithAssessment( 100 ) );
		$this->lessonAccess->method( 'canRead' )->willReturn( true );

		self::assertSame( 42, $this->policy->resolveAccessibleLesson( 5, 100 )?->id );
	}

	public function test_returns_null_when_no_access(): void {
		$this->records->method( 'findByStudent' )->willReturn( array() );

		self::assertNull( $this->policy->resolveAccessibleLesson( 5, 100 ) );
	}

	private function groupLesson( int $id, int $lessonId, ?string $scheduledAt = null ): GroupLessonDTO {
		return new GroupLessonDTO(
			id              : $id,
			groupId         : 10,
			lessonId        : $lessonId,
			position        : 0,
			workIdsSnapshot : null,
			extraWorkIds    : array(),
			scheduledAt     : $scheduledAt,
			endsAt          : null,
			isPinned        : false,
			teacherUserId   : null,
			visibility      : 'open',
			openedAt        : '2024-01-01 00:00:00',
			homeworkDueAt   : null,
			allowLate       : true,
			recordingUrl    : null,
			createdByUserId : null,
			updatedByUserId : null,
		);
	}

	private function lessonWithAssessment( int $assessmentId ): LessonDTO {
		return LessonDTO::fromArray( array(
			'subject_key' => 'inf',
			'topic'       => 'T',
			'steps'       => array(
				array( 'key' => 's1', 'type' => 'assessment', 'payload' => array( 'ref' => $assessmentId ) ),
			),
		) );
	}
}
