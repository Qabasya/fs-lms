<?php
/** Runtime eligibility for independent courses. */
declare( strict_types=1 );
namespace Inc\Services\Enrollment;

use Inc\DTO\Enrollment\StudentRecordDTO;
use Inc\DTO\Course\CourseDTO;
use Inc\Managers\Course\CourseManager;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Services\Course\SelfPacedContentGuard;

class SelfPacedAccessPolicy {
	public function __construct(
		private readonly GroupsRepository $groups,
		private readonly StudentRecordRepository $records,
		private readonly CourseManager $courses,
		private readonly SelfPacedContentGuard $content,
		private readonly GroupLessonRepository $lessons,
		private readonly \Inc\Managers\Course\LessonManager $lessonBank,
	) {}

	/** Only ordinary, active, non-trial primary enrollment grants admission. */
	public function eligibleBasis( int $personId, array $subjects ): ?StudentRecordDTO {
		foreach ( $this->records->findActiveByStudent( $personId ) as $record ) {
			$group = $this->groups->findById( $record->groupId );
			if ( ! $record->isTrial && $record->isActive() && $group
				&& null === $group->deleted_at && ! (int) ( $group->is_shared ?? 0 )
				&& ! (int) ( $group->self_paced_course_id ?? 0 )
				&& in_array( $group->access_mode ?? 'scheduled', array( 'scheduled', 'individual' ), true )
				&& in_array( $group->subject_key, $subjects, true ) ) {
				return $record;
			}
		}
		return null;
	}

	/** Validate persisted program and content, including when new admission is disabled. */
	public function assertReady( CourseDTO $course ): void {
		$group = $this->groups->findById( $course->serviceGroupId );
		if ( 'publish' !== $course->status || ! $group || null !== $group->deleted_at
			|| 'open' !== $group->access_mode || (int) ( $group->is_shared ?? 0 )
			|| (int) $group->course_id !== $course->id
			|| (int) ( $group->self_paced_course_id ?? 0 ) !== $course->id ) {
			throw new \DomainException( 'Самостоятельный курс недоступен.' );
		}
		foreach ( $course->lessonIds() as $lessonId ) {
			$lesson = $this->lessonBank->get( $lessonId );
			if ( ! $lesson || 'publish' !== $lesson->status ) {
				throw new \DomainException( 'Урок самостоятельного курса не опубликован.' );
			}
		}
		$data = $course->toArray();
		$data['self_paced_enabled'] = true;
		$this->content->assertCourse( CourseDTO::fromArray( $data ) );
		$present = array();
		foreach ( $this->lessons->listByGroup( $course->serviceGroupId ) as $row ) {
			if ( 'open' === $row->visibility ) {
				$present[] = $row->lessonId;
			}
		}
		if ( ! $course->lessonIds() || array_diff( $course->lessonIds(), $present ) ) {
			throw new \DomainException( 'Программа самостоятельного курса ещё не готова.' );
		}
	}

	public function canContinue( StudentRecordDTO $record ): bool {
		$group = $this->groups->findById( $record->groupId );
		if ( ! $group || ! (int) ( $group->self_paced_course_id ?? 0 ) ) {
			return true;
		}
		if ( ! $record->isActive() || $record->isTrial || ! $record->selfPacedBasisSubject
			|| ! $this->eligibleBasis( $record->studentPersonId, array( $record->selfPacedBasisSubject ) ) ) {
			return false;
		}
		$course = $this->courses->get( (int) $group->self_paced_course_id );
		if ( ! $course || $course->serviceGroupId !== $record->groupId ) {
			return false;
		}
		try {
			$this->assertReady( $course );
			return true;
		} catch ( \DomainException | \InvalidArgumentException $error ) {
			return false;
		}
	}

	/** Existing group guards still check membership for ordinary groups. */
	public function canAccessGroup( int $groupId, int $personId ): bool {
		$group = $this->groups->findById( $groupId );
		if ( ! $group || ! (int) ( $group->self_paced_course_id ?? 0 ) ) {
			return true;
		}
		foreach ( $this->records->findAllByStudentAndGroup( $personId, $groupId ) as $record ) {
			if ( $this->canContinue( $record ) ) {
				return true;
			}
		}
		return false;
	}
	public function canParentAccessGroup( int $groupId, int $parentPersonId ): bool {
		$group = $this->groups->findById( $groupId );
		if ( ! $group || ! (int) ( $group->self_paced_course_id ?? 0 ) ) {
			return true;
		}
		foreach ( $this->records->findAllByParent( $parentPersonId ) as $record ) {
			if ( $record->groupId === $groupId && $this->canContinue( $record ) ) {
				return true;
			}
		}
		return false;
	}

}
