<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Enrollment\StudentRecordDTO;
use Inc\Enums\Access\AccessLevel;
use Inc\Enums\Enrollment\EnrollmentStatus;
use Inc\Repositories\OptionsRepositories\ExpulsionPolicyRepository;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Course\LessonAccessPolicy;
use Inc\Services\Course\LessonVisibilityService;
use PHPUnit\Framework\TestCase;

class LessonAccessPolicyTest extends TestCase {

	/** Фиксированное «сейчас» для правила авто-открытия по дате (#13). */
	private const NOW = '2024-06-01 00:00:00';

	private StudentRecordRepository&\PHPUnit\Framework\MockObject\MockObject $studentRecords;
	private GroupLessonRepository&\PHPUnit\Framework\MockObject\MockObject $groupLessons;
	private GroupsRepository&\PHPUnit\Framework\MockObject\MockObject $groups;
	private LessonAccessPolicy $policy;

	protected function setUp(): void {
		parent::setUp();
		$this->studentRecords   = $this->createMock( StudentRecordRepository::class );
		$this->groupLessons     = $this->createMock( GroupLessonRepository::class );
		$this->groups           = $this->createMock( GroupsRepository::class );
		$this->setGroupAccessMode( 'scheduled' );

		// Мок повторяет правило LessonVisibilityService::effectiveVisibility против NOW:
		// hidden + наступившая scheduled_at → open; иначе — сырая видимость.
		$visibility = $this->createMock( LessonVisibilityService::class );
		$visibility->method( 'effectiveVisibility' )->willReturnCallback(
			static function ( GroupLessonDTO $lesson ): string {
				if ( 'hidden' === $lesson->visibility
					&& null !== $lesson->scheduledAt
					&& self::NOW >= $lesson->scheduledAt
				) {
					return 'open';
				}
				return $lesson->visibility;
			}
		);

		$this->policy           = new LessonAccessPolicy(
			$this->studentRecords,
			$this->groupLessons,
			new ExpulsionPolicyRepository(),
			$visibility,
			$this->groups,
		);
		$GLOBALS['_test_options'] = [];
	}

	/** Группа в моке репозитория отдаёт заданный access_mode. */
	private function setGroupAccessMode( string $mode ): void {
		$group              = new \stdClass();
		$group->access_mode = $mode;
		$this->groups->method( 'findById' )->willReturn( $group );
	}

	// --- resolve() matrix ---

	public function test_hidden_lesson_returns_none_for_active_student(): void {
		$record = $this->makeRecord( EnrollmentStatus::Active );
		$lesson = $this->makeLesson( visibility: 'hidden', openedAt: '2024-01-10 10:00:00' );

		self::assertSame( AccessLevel::None, $this->policy->resolve( $record, $lesson ) );
	}

	// --- #13: авто-открытие скрытого урока по наступившей дате ---

	public function test_hidden_lesson_with_past_scheduled_date_auto_opens_read_submit(): void {
		// Скрытый урок с прошедшей датой занятия (зачислен раньше даты) → сдача доступна.
		$record = $this->makeRecord( EnrollmentStatus::Active, enrolledAt: '2024-01-01 00:00:00' );
		$lesson = $this->makeLesson( visibility: 'hidden', openedAt: null, scheduledAt: '2024-03-01 09:00:00' );

		self::assertSame( AccessLevel::ReadSubmit, $this->policy->resolve( $record, $lesson ) );
	}

	public function test_hidden_lesson_with_future_scheduled_date_stays_none(): void {
		// Дата занятия ещё не наступила → урок остаётся закрытым.
		$record = $this->makeRecord( EnrollmentStatus::Active );
		$lesson = $this->makeLesson( visibility: 'hidden', openedAt: null, scheduledAt: '2024-09-01 09:00:00' );

		self::assertSame( AccessLevel::None, $this->policy->resolve( $record, $lesson ) );
	}

	public function test_hidden_lesson_auto_open_before_enrollment_is_read_only(): void {
		// Урок открылся по дате ДО зачисления ученика (бэк-каталог) → только чтение.
		$record = $this->makeRecord( EnrollmentStatus::Active, enrolledAt: '2024-04-01 00:00:00' );
		$lesson = $this->makeLesson( visibility: 'hidden', openedAt: null, scheduledAt: '2024-03-01 09:00:00' );

		self::assertSame( AccessLevel::Read, $this->policy->resolve( $record, $lesson ) );
	}

	public function test_active_student_lesson_opened_after_enrollment_gets_read_submit(): void {
		$record = $this->makeRecord( EnrollmentStatus::Active, enrolledAt: '2024-01-05 00:00:00' );
		$lesson = $this->makeLesson( visibility: 'open', openedAt: '2024-01-10 00:00:00' );

		self::assertSame( AccessLevel::ReadSubmit, $this->policy->resolve( $record, $lesson ) );
	}

	public function test_active_student_lesson_opened_before_enrollment_gets_read(): void {
		$record = $this->makeRecord( EnrollmentStatus::Active, enrolledAt: '2024-02-01 00:00:00' );
		$lesson = $this->makeLesson( visibility: 'open', openedAt: '2024-01-10 00:00:00' );

		self::assertSame( AccessLevel::Read, $this->policy->resolve( $record, $lesson ) );
	}

	public function test_active_student_not_yet_opened_lesson_gets_read(): void {
		$record = $this->makeRecord( EnrollmentStatus::Active, enrolledAt: '2024-01-01 00:00:00' );
		$lesson = $this->makeLesson( visibility: 'open', openedAt: null );

		self::assertSame( AccessLevel::Read, $this->policy->resolve( $record, $lesson ) );
	}

	public function test_expelled_retain_lesson_opened_before_expulsion_gets_read(): void {
		$GLOBALS['_test_options']['fs_lms_expulsion_retention_policy'] = 'retain';
		$record = $this->makeRecord( EnrollmentStatus::Expelled, expelledAt: '2024-03-01 00:00:00' );
		$lesson = $this->makeLesson( visibility: 'open', openedAt: '2024-02-15 00:00:00' );

		self::assertSame( AccessLevel::Read, $this->policy->resolve( $record, $lesson ) );
	}

	public function test_expelled_retain_lesson_opened_after_expulsion_gets_none(): void {
		$GLOBALS['_test_options']['fs_lms_expulsion_retention_policy'] = 'retain';
		$record = $this->makeRecord( EnrollmentStatus::Expelled, expelledAt: '2024-02-01 00:00:00' );
		$lesson = $this->makeLesson( visibility: 'open', openedAt: '2024-03-01 00:00:00' );

		self::assertSame( AccessLevel::None, $this->policy->resolve( $record, $lesson ) );
	}

	public function test_expelled_block_policy_always_returns_none(): void {
		$GLOBALS['_test_options']['fs_lms_expulsion_retention_policy'] = 'block';
		$record = $this->makeRecord( EnrollmentStatus::Expelled, expelledAt: '2024-03-01 00:00:00' );
		$lesson = $this->makeLesson( visibility: 'open', openedAt: '2024-01-01 00:00:00' );

		self::assertSame( AccessLevel::None, $this->policy->resolve( $record, $lesson ) );
	}

	public function test_expelled_retain_null_opened_at_gets_none(): void {
		$GLOBALS['_test_options']['fs_lms_expulsion_retention_policy'] = 'retain';
		$record = $this->makeRecord( EnrollmentStatus::Expelled, expelledAt: '2024-03-01 00:00:00' );
		$lesson = $this->makeLesson( visibility: 'open', openedAt: null );

		self::assertSame( AccessLevel::None, $this->policy->resolve( $record, $lesson ) );
	}

	public function test_archived_lesson_follows_same_matrix_as_open(): void {
		$record = $this->makeRecord( EnrollmentStatus::Active, enrolledAt: '2024-01-01 00:00:00' );
		$lesson = $this->makeLesson( visibility: 'archived', openedAt: '2024-01-05 00:00:00' );

		self::assertSame( AccessLevel::ReadSubmit, $this->policy->resolve( $record, $lesson ) );
	}

	// --- Эпик 15: открытая группа (access_mode=open) ---

	public function test_open_group_active_student_gets_read_submit_regardless_of_dates(): void {
		// Урок открылся ДО зачисления (в scheduled-группе это read-only бэк-каталог).
		$this->groups = $this->createMock( GroupsRepository::class );
		$this->setGroupAccessMode( 'open' );
		$this->policy = $this->rebuildPolicy();

		$record = $this->makeRecord( EnrollmentStatus::Active, enrolledAt: '2024-02-01 00:00:00' );
		$lesson = $this->makeLesson( visibility: 'open', openedAt: '2024-01-10 00:00:00' );

		self::assertSame( AccessLevel::ReadSubmit, $this->policy->resolve( $record, $lesson ) );
	}

	public function test_open_group_hidden_lesson_still_none(): void {
		// Ручное сокрытие урока автором уважается и в открытой группе.
		$this->groups = $this->createMock( GroupsRepository::class );
		$this->setGroupAccessMode( 'open' );
		$this->policy = $this->rebuildPolicy();

		$record = $this->makeRecord( EnrollmentStatus::Active );
		$lesson = $this->makeLesson( visibility: 'hidden', openedAt: null );

		self::assertSame( AccessLevel::None, $this->policy->resolve( $record, $lesson ) );
	}

	public function test_open_group_expelled_student_follows_retention_policy(): void {
		$GLOBALS['_test_options']['fs_lms_expulsion_retention_policy'] = 'block';
		$this->groups = $this->createMock( GroupsRepository::class );
		$this->setGroupAccessMode( 'open' );
		$this->policy = $this->rebuildPolicy();

		$record = $this->makeRecord( EnrollmentStatus::Expelled, expelledAt: '2024-03-01 00:00:00' );
		$lesson = $this->makeLesson( visibility: 'open', openedAt: '2024-01-01 00:00:00' );

		self::assertSame( AccessLevel::None, $this->policy->resolve( $record, $lesson ) );
	}

	private function rebuildPolicy(): LessonAccessPolicy {
		$visibility = $this->createMock( LessonVisibilityService::class );
		$visibility->method( 'effectiveVisibility' )->willReturnCallback(
			static fn( GroupLessonDTO $lesson ): string => $lesson->visibility
		);
		return new LessonAccessPolicy(
			$this->studentRecords,
			$this->groupLessons,
			new ExpulsionPolicyRepository(),
			$visibility,
			$this->groups,
		);
	}

	// --- canRead() / canSubmit() delegation ---

	public function test_can_read_returns_true_when_record_grants_access(): void {
		$record = $this->makeRecord( EnrollmentStatus::Active, enrolledAt: '2024-01-01 00:00:00' );
		$lesson = $this->makeLesson( visibility: 'open', openedAt: '2024-01-05 00:00:00', groupId: 10 );
		$this->groupLessons->method( 'find' )->with( 42 )->willReturn( $lesson );
		$this->studentRecords->method( 'findAllByStudentAndGroup' )->with( 5, 10 )->willReturn( [ $record ] );

		self::assertTrue( $this->policy->canRead( 5, 42 ) );
	}

	public function test_can_submit_returns_false_for_back_catalog(): void {
		$record = $this->makeRecord( EnrollmentStatus::Active, enrolledAt: '2024-02-01 00:00:00' );
		$lesson = $this->makeLesson( visibility: 'open', openedAt: '2024-01-01 00:00:00', groupId: 10 );
		$this->groupLessons->method( 'find' )->with( 42 )->willReturn( $lesson );
		$this->studentRecords->method( 'findAllByStudentAndGroup' )->willReturn( [ $record ] );

		self::assertFalse( $this->policy->canSubmit( 5, 42 ) );
	}

	public function test_can_read_returns_false_when_lesson_not_found(): void {
		$this->groupLessons->method( 'find' )->willReturn( null );

		self::assertFalse( $this->policy->canRead( 5, 99 ) );
	}

	// --- helpers ---

	private function makeRecord(
		EnrollmentStatus $status,
		string $enrolledAt = '2024-01-01 00:00:00',
		?string $expelledAt = null,
	): StudentRecordDTO {
		return StudentRecordDTO::fromArray( [
			'id'                  => 1,
			'student_person_id'   => 5,
			'parent_person_id'    => 0,
			'group_id'            => 10,
			'snapshot_last_name'  => 'Test',
			'snapshot_first_name' => 'User',
			'status'              => $status->value,
			'enrolled_at'         => $enrolledAt,
			'expelled_at'         => $expelledAt,
			'created_at'          => '2024-01-01 00:00:00',
			'updated_at'          => '2024-01-01 00:00:00',
		] );
	}

	private function makeLesson(
		string $visibility,
		?string $openedAt,
		int $groupId = 10,
		?array $workIdsSnapshot = null,
		?string $scheduledAt = null,
	): GroupLessonDTO {
		return new GroupLessonDTO(
			id              : 42,
			groupId         : $groupId,
			lessonId        : 1,
			position        : 0,
			workIdsSnapshot : $workIdsSnapshot,
			extraWorkIds    : [],
			scheduledAt     : $scheduledAt,
			endsAt          : null,
			isPinned        : false,
			teacherUserId   : null,
			visibility      : $visibility,
			openedAt        : $openedAt,
			homeworkDueAt   : null,
			allowLate       : true,
			recordingUrl    : null,
			createdByUserId : null,
			updatedByUserId : null,
		);
	}
}
