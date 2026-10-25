<?php

declare( strict_types=1 );

namespace Inc\Services\Enrollment;

use Inc\Contracts\ClockInterface;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Enrollment\StudentRecordInputDTO;
use Inc\DTO\Log\Events\EnrollmentStatusEvent;
use Inc\Enums\Course\AccessMode;
use Inc\Enums\Log\AuditAction;
use Inc\Enums\Log\LogEvent;
use Inc\Enums\Profile\NotificationType;
use Inc\Enums\Wp\PageRoutes;
use Inc\Managers\Course\CourseManager;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Email\EmailService;
use Inc\Services\Exam\ExamAudienceResolver;
use Inc\Services\Profile\NotificationService;
use Inc\Shared\PluginLogger;

/**
 * Class OpenGroupEnrollmentService
 *
 * Лёгкая запись СУЩЕСТВУЮЩИХ учеников в открытые и общие группы (Эпик 15).
 *
 * @package Inc\Services\Enrollment
 *
 * В отличие от полного зачисления (EnrollmentService: заявка → договор → приказ →
 * создание persons/WP-аккаунтов) здесь создаётся только вторая параллельная запись
 * student_records: без документов, родитель и снапшоты копируются из последней
 * записи ученика. Инвариант «создание записи ⇒ событие зачисления» соблюдается:
 * каждый добавленный ученик диспатчит LogEvent::StudentEnrolled (журнал аудита).
 *
 * Гард: работает ТОЛЬКО с группами access_mode='open' и с общими группами (`groups.is_shared`) —
 * путь в обычные расписаночные группы остаётся один (полный флоу зачисления с документами).
 *
 * Общая (дополнительная) группа — курс и расписание поверх основной группы ученика, а не зачисление:
 * - письма «вам открыт курс» не уходят (для открытой группы — как раньше);
 * - generic-хук `fs_lms_student_enrolled` не вызывается (AdSync и другие модули реагируют на зачисление,
 *   а ученик уже зачислен основной группой);
 * - преподавателю вместо плитки на каждого ученика уходит одна сводная плитка на вызов.
 */
class OpenGroupEnrollmentService {

	public function __construct(
		private readonly StudentRecordRepository     $studentRecords,
		private readonly GroupsRepository            $groups,
		private readonly LogEventDispatcherInterface $logEvents,
		private readonly ClockInterface              $clock,
		private readonly PersonRepository            $persons,
		private readonly CourseManager               $courses,
		private readonly EmailService                $email,
		private readonly ExamAudienceResolver        $audience,
		private readonly NotificationService         $notifications,
	) {}

	/**
	 * Записывает существующих учеников в открытую или общую группу.
	 *
	 * @param int[] $studentPersonIds ID учеников (persons).
	 * @param int   $groupId          ID открытой или общей группы.
	 * @param int   $actorUserId      WP-пользователь, выполняющий добавление.
	 *
	 * @return array{added:int, skipped:int} skipped — уже активные в группе или без исходных записей.
	 * @throws \InvalidArgumentException Группа не найдена или не открытая и не общая.
	 */
	public function enrollMany( array $studentPersonIds, int $groupId, int $actorUserId ): array {
		$group  = $this->enrollableGroup( $groupId );
		$shared = $this->isShared( $group );

		$courseId    = (int) ( $group->course_id ?? 0 );
		$courseTitle = ( $courseId > 0 ? $this->courses->get( $courseId )?->title : null ) ?: (string) $group->name;

		$added   = 0;
		$skipped = 0;
		foreach ( array_unique( array_filter( array_map( 'intval', $studentPersonIds ) ) ) as $studentPersonId ) {
			if ( null === $this->enrollOne( $studentPersonId, $groupId, $actorUserId, $shared ) ) {
				++$skipped;
				continue;
			}
			if ( ! $shared ) {
				$this->notify( $studentPersonId, $courseTitle );
			}
			++$added;
		}

		if ( $shared && $added > 0 ) {
			$this->notifyTeacher( $groupId, $added );
		}

		return array( 'added' => $added, 'skipped' => $skipped );
	}

	/**
	 * Ученики предмета («направления»), которых ещё нет в группе: основа для массового добавления.
	 *
	 * Состав предмета — тот же отбор, что у аудитории экзамена ({@see ExamAudienceResolver::studentPersonIds()}):
	 * активные непробные записи в неудалённых основных группах предмета.
	 *
	 * @return array{ids: list<int>, total: int, already: int} ids — кого добавить; total — всего учеников предмета; already — уже в группе.
	 * @throws \InvalidArgumentException Группа не найдена или не открытая и не общая.
	 */
	public function planFromSubject( string $subjectKey, int $groupId ): array {
		$this->enrollableGroup( $groupId );

		$inGroup = array();
		foreach ( $this->studentRecords->findActiveByGroupId( $groupId ) as $record ) {
			$inGroup[ $record->studentPersonId ] = true;
		}

		$ids     = array();
		$already = 0;
		$all     = $this->audience->studentPersonIds( $subjectKey );
		foreach ( $all as $personId ) {
			if ( isset( $inGroup[ $personId ] ) ) {
				++$already;
				continue;
			}
			$ids[] = $personId;
		}

		return array( 'ids' => $ids, 'total' => count( $all ), 'already' => $already );
	}

	/**
	 * Группа, в которую разрешена лёгкая запись.
	 *
	 * @throws \InvalidArgumentException Группа не найдена или не открытая и не общая.
	 */
	private function enrollableGroup( int $groupId ): object {
		$group = $this->groups->findById( $groupId );
		if ( ! $group ) {
			throw new \InvalidArgumentException( 'Группа не найдена.' );
		}
		$open = AccessMode::Open === AccessMode::fromValueOrDefault( (string) ( $group->access_mode ?? '' ) );
		if ( ! $open && ! $this->isShared( $group ) ) {
			throw new \InvalidArgumentException( 'Добавлять существующих учеников можно только в открытые и общие группы.' );
		}

		return $group;
	}

	/** Общая (дополнительная) группа: `groups.is_shared`. */
	private function isShared( object $group ): bool {
		return 1 === (int) ( $group->is_shared ?? 0 );
	}

	/** Письмо «вам открыт курс» (П11); сбой почты не роняет запись. */
	private function notify( int $studentPersonId, string $courseTitle ): void {
		try {
			$person = $this->persons->find( $studentPersonId );
			if ( null !== $person && null !== $person->wpUserId ) {
				$this->email->sendCourseGranted( $person->wpUserId, $courseTitle, $studentPersonId );
			}
		} catch ( \Throwable $e ) {
			PluginLogger::exception( 'OpenGroupEnrollment', $e, array( 'student_person_id' => $studentPersonId ) );
		}
	}

	/** Одна сводная плитка преподавателю общей группы («Добавлено учеников: N»); сбой уведомления запись не откатывает. */
	private function notifyTeacher( int $groupId, int $added ): void {
		try {
			$teacherUserId = $this->notifications->groupTeacherUserId( $groupId );
			if ( null === $teacherUserId ) {
				return;
			}
			$this->notifications->push(
				array( $teacherUserId ),
				NotificationType::StudentJoined,
				sprintf( 'joined_bulk:%d:%s', $groupId, bin2hex( random_bytes( 4 ) ) ),
				array(
					'student_name' => sprintf( 'Добавлено учеников: %d', $added ),
					'group_name'   => $this->notifications->groupName( $groupId ),
				),
				PageRoutes::UserProfile->url(),
				$groupId,
				'group',
				$groupId
			);
		} catch ( \Throwable $e ) {
			PluginLogger::exception( 'OpenGroupEnrollment', $e, array( 'group_id' => $groupId ) );
		}
	}

	/**
	 * @return int|null ID созданной записи; null — пропущен (дубль или у ученика нет записей).
	 */
	private function enrollOne( int $studentPersonId, int $groupId, int $actorUserId, bool $shared ): ?int {
		if ( $this->studentRecords->existsActive( $studentPersonId, $groupId ) ) {
			return null;
		}

		// Родитель и снапшоты ФИО/школы — из последней записи ученика; документы не нужны (NULL).
		$latest = $this->studentRecords->findByStudent( $studentPersonId )[0] ?? null;
		// Ученик с одним лишь временным доступом ещё не зачислен: родителя, которого копировать, нет.
		if ( null === $latest || $latest->isTrial ) {
			return null;
		}

		$now      = $this->clock->now();
		$recordId = $this->studentRecords->create( new StudentRecordInputDTO(
			studentPersonId:    $studentPersonId,
			parentPersonId:     $latest->parentPersonId,
			status:             'active',
			enrolledAt:         $now,
			createdAt:          $now,
			updatedAt:          $now,
			groupId:            $groupId,
			snapshotLastName:   $latest->snapshotLastName,
			snapshotFirstName:  $latest->snapshotFirstName,
			snapshotMiddleName: $latest->snapshotMiddleName,
			snapshotSchool:     $latest->snapshotSchool,
			snapshotGrade:      $latest->snapshotGrade,
			enrolledByUserId:   $actorUserId ?: null,
		) );

		// Инвариант «запись ⇒ событие»: журнал аудита (плитку преподавателю на каждого общая группа гасит, см. NotificationSubscriber).
		$this->logEvents->dispatch(
			LogEvent::StudentEnrolled,
			new EnrollmentStatusEvent( $actorUserId, AuditAction::EnrollStudent, $studentPersonId, $recordId, $groupId )
		);
		// Generic-хук зачисления — для модулей; запись общей группы зачислением не является.
		if ( ! $shared ) {
			do_action( 'fs_lms_student_enrolled', $recordId, $studentPersonId );
		}

		return $recordId;
	}
}
