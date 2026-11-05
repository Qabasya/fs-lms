<?php
/** Quiet, serialized self-enrollment of an existing student. */
declare( strict_types=1 );
namespace Inc\Services\Enrollment;
use Inc\Contracts\ClockInterface;
use Inc\DTO\Enrollment\StudentRecordInputDTO;
use Inc\Enums\Access\UserRole;
use Inc\Managers\Course\CourseManager;
use Inc\Managers\Wp\PostManager;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
class SelfPacedEnrollmentService {
	public function __construct(
		private readonly GroupsRepository $groups,
		private readonly StudentRecordRepository $records,
		private readonly PersonRepository $persons,
		private readonly CourseManager $courses,
		private readonly PostManager $posts,
		private readonly SelfPacedAccessPolicy $access,
		private readonly ClockInterface $clock,
		private readonly \Inc\Managers\Person\UserManager $users,
		private readonly \Inc\Services\Assessment\ExamLockService $examLock,
	) {}
	/** No enrollment events, provisioning, documents, mail or notifications. */
	public function start( int $courseId, int $userId ): array {
		$user = $this->users->find( $userId );
		$person = $this->persons->findByWpUserId( $userId );
		if ( $courseId < 1 || ! $user || ! in_array( UserRole::FSStudent->value, $user->roles, true ) || ! $person || ! $person->isStudent ) {
			throw new \DomainException( 'Начать курс может только текущий ученик.' );
		}
		return $this->groups->withCourseTransaction( $courseId, function () use ( $courseId, $userId, $person ): array {
			if ( $this->examLock->isLocked( $person->id ) ) {
				throw new \DomainException( 'Начать курс во время экзамена нельзя.' );
			}
			$this->posts->clearCache( $courseId );
			$course = $this->courses->get( $courseId );
			if ( ! $course ) {
				throw new \DomainException( 'Курс не найден.' );
			}
			$this->access->assertReady( $course );
			$history = $this->records->findAllByStudentAndGroup( $person->id, $course->serviceGroupId );
			if ( count( $history ) > 1 ) {
				throw new \DomainException( 'Требуется проверить историю записи на самостоятельный курс.' );
			}
			$record = $history[0] ?? null;
			if ( $record && $record->isTrial ) {
				throw new \DomainException( 'Пробная запись не даёт доступа к самостоятельному курсу.' );
			}
			$subjects = $record && $record->selfPacedBasisSubject ? array( $record->selfPacedBasisSubject ) : $course->audienceSubjectKeys;
			if ( ! $record && ! $course->selfPacedEnabled ) {
				throw new \DomainException( 'Набор на курс закрыт.' );
			}
			$basis = $this->access->eligibleBasis( $person->id, $subjects );
			if ( ! $basis || ( $record && ! $record->selfPacedBasisSubject ) ) {
				throw new \DomainException( 'Нет действующего основного зачисления для этого курса.' );
			}
			$now = $this->clock->now();
			if ( $record ) {
				$id = $record->id;
				if ( ! $record->isActive() && ! $this->records->update( $id, array( 'status' => 'active', 'expelled_at' => null, 'expelled_by_user_id' => null, 'expel_reason' => null, 'updated_at' => $now ) ) ) {
					throw new \RuntimeException( 'Не удалось продолжить курс.' );
				}
			} else {
				$group = $this->groups->findById( $basis->groupId );
				$id = $this->records->create( new StudentRecordInputDTO(
					studentPersonId: $person->id, parentPersonId: $basis->parentPersonId,
					status: 'active', enrolledAt: $now, createdAt: $now, updatedAt: $now,
					groupId: $course->serviceGroupId, snapshotLastName: $basis->snapshotLastName,
					snapshotFirstName: $basis->snapshotFirstName, snapshotMiddleName: $basis->snapshotMiddleName,
					snapshotSchool: $basis->snapshotSchool, snapshotGrade: $basis->snapshotGrade,
					enrolledByUserId: $userId, selfPacedBasisSubject: (string) $group->subject_key,
				) );
				if ( $id < 1 ) {
					throw new \RuntimeException( 'Не удалось начать курс.' );
				}
			}
			return array( 'course_id' => $courseId, 'group_id' => $course->serviceGroupId, 'record_id' => $id );
		} );
	}
}
