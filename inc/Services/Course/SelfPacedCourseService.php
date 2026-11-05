<?php
/**
 * Prepare a self-paced course atomically without enrolling students.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\DTO\Course\CourseDTO;
use Inc\Enums\Access\Capability;
use Inc\Enums\Course\AssignmentPolicy;
use Inc\Managers\Course\CourseManager;
use Inc\Managers\Wp\PostManager;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\OptionsRepositories\AcademicPeriodRepository;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;

/** Explicit preparation only: never enrolls students or emits enrollment events. */
class SelfPacedCourseService {
	/** Receive the existing course, assignment and persistence services. */
	public function __construct(
		private readonly CourseManager $courses,
		private readonly PostManager $posts,
		private readonly GroupsRepository $groups,
		private readonly GroupLessonRepository $lessons,
		private readonly CourseAssignmentService $assignment,
		private readonly SelfPacedContentGuard $guard,
		private readonly SubjectRepository $subjects,
		private readonly AcademicPeriodRepository $periods,
	) {}

	/**
	 * Access settings belong to the course author, with an administrator override.
	 *
	 * @throws \InvalidArgumentException Editor is outside the course scope.
	 */
	public function assertEditor( CourseDTO $course, int $userId ): void {
		if ( $course->authorId !== $userId
			&& ! user_can( $userId, Capability::Admin->value ) ) {
			throw new \InvalidArgumentException( 'Изменять доступ может только автор курса или администратор.' );
		}
	}

	/** Save an optional access patch and course details in the same transaction. */
	public function save( int $courseId, ?bool $enabled, ?array $audience, int $actorId, callable $saveDetails ): bool {
		return $this->groups->withSelfPacedLifecycleLock( fn(): bool => $this->saveLocked( $courseId, $enabled, $audience, $actorId, $saveDetails ) );
	}

	private function saveLocked( int $courseId, ?bool $enabled, ?array $audience, int $actorId, callable $saveDetails ): bool {
		$this->subjects->refreshCache();
		$this->periods->refreshCache();
		try {
			return $this->groups->withCourseTransaction(
				$courseId,
				function () use ( $courseId, $enabled, $audience, $actorId, $saveDetails ): bool {
					$this->posts->clearCache( $courseId );
					$course = $this->courses->get( $courseId );
					if ( null === $course ) {
						throw new \InvalidArgumentException( 'Курс не найден.' );
					}
					if ( null !== $enabled || null !== $audience || $course->selfPacedEnabled ) {
						$this->assertEditor( $course, $actorId );
					}
					$settings                          = $course->toArray();
					$settings['self_paced_enabled']    = $enabled ?? $course->selfPacedEnabled;
					$settings['audience_subject_keys'] = $audience ?? $course->audienceSubjectKeys;
					$known                             = array();
					foreach ( $this->subjects->readAll() as $subject ) {
						$known[] = $subject->key;
					}
					foreach ( $settings['audience_subject_keys'] as $key ) {
						if ( ! is_string( $key ) || ! in_array( $key, $known, true ) ) {
							throw new \InvalidArgumentException( 'Выбрано неизвестное направление аудитории.' );
						}
					}
					$settings['audience_subject_keys'] = array_values( array_unique( $settings['audience_subject_keys'] ) );
					$candidate                         = CourseDTO::fromArray( $settings );
					$this->guard->assertCourse( $candidate );
					$groupId = $course->serviceGroupId;
					if ( $candidate->selfPacedEnabled ) {
						$group = $this->groups->findBySelfPacedCourse( $courseId );
						if ( null === $group ) {
							if ( $groupId > 0 ) {
								throw new \RuntimeException( 'Нарушена связь служебной группы курса.' );
							}
							$groupId = $this->groups->create(
								array(
									'subject_key'          => $course->subjectKey,
									'academic_period_id'   => $this->periods->getCurrentPeriod()?->id ?? throw new \InvalidArgumentException( 'Не задан текущий учебный период.' ),
									'name'                 => $course->title,
									'access_mode'          => 'open',
									'is_shared'            => 0,
									'meetings'             => '[]',
									'self_paced_course_id' => $courseId,
									'course_id'            => $courseId,
								)
							);
							if ( $groupId < 1 ) {
								throw new \RuntimeException( 'Не удалось создать служебную группу.' );
							}
							$group = $this->groups->findById( $groupId );
						} else {
							if ( $groupId > 0 && $groupId !== (int) $group->id ) {
								throw new \RuntimeException( 'Нарушена обратная связь служебной группы.' );
							}
							$groupId = (int) $group->id;
						}
						if ( ! $group || null !== $group->deleted_at || 'open' !== $group->access_mode
						|| 0 !== (int) $group->is_shared || (int) $group->course_id !== $courseId
						|| (int) $group->self_paced_course_id !== $courseId ) {
							throw new \RuntimeException( 'Служебная группа несовместима с курсом.' );
						}
						$this->assignment->assign( $groupId, $courseId, $actorId, AssignmentPolicy::Append, false );
						$present = array_map( static fn( $row ): int => $row->lessonId, $this->lessons->listByGroup( $groupId ) );
						if ( array_diff( $course->lessonIds(), $present ) ) {
							throw new \RuntimeException( 'Программа служебной группы подготовлена не полностью.' );
						}
					}
					$this->courses->saveAccess( $courseId, $candidate->selfPacedEnabled, $candidate->audienceSubjectKeys, $groupId );
					if ( ! $saveDetails() ) {
						throw new \RuntimeException( 'Не удалось сохранить параметры курса.' );
					}
					return true;
				}
			);
		} finally {
			$this->posts->clearCache( $courseId );
			foreach ( $this->courses->get( $courseId )?->lessonIds() ?? array() as $lessonId ) {
				$this->posts->clearCache( (int) $lessonId );
			}
		}
	}
}
