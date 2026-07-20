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
use Inc\Managers\Course\CourseManager;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Email\EmailService;
use Inc\Shared\PluginLogger;

/**
 * Class OpenGroupEnrollmentService
 *
 * Лёгкая запись СУЩЕСТВУЮЩИХ учеников в открытые группы (Эпик 15).
 *
 * @package Inc\Services\Enrollment
 *
 * В отличие от полного зачисления (EnrollmentService: заявка → договор → приказ →
 * создание persons/WP-аккаунтов) здесь создаётся только вторая параллельная запись
 * student_records: без документов, родитель и снапшоты копируются из последней
 * записи ученика. Инвариант «создание записи ⇒ событие зачисления» соблюдается:
 * каждый добавленный ученик диспатчит LogEvent::StudentEnrolled (журнал аудита)
 * + generic-хук fs_lms_student_enrolled (опциональные модули).
 *
 * Гард: работает ТОЛЬКО с группами access_mode='open' — путь в расписаночные
 * группы остаётся один (полный флоу зачисления с документами).
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
	) {}

	/**
	 * Записывает существующих учеников в открытую группу.
	 *
	 * @param int[] $studentPersonIds ID учеников (persons).
	 * @param int   $groupId          ID открытой группы.
	 * @param int   $actorUserId      WP-пользователь, выполняющий добавление.
	 *
	 * @return array{added:int, skipped:int} skipped — уже активные в группе или без исходных записей.
	 * @throws \InvalidArgumentException Группа не найдена или не является открытой.
	 */
	public function enrollMany( array $studentPersonIds, int $groupId, int $actorUserId ): array {
		$group = $this->groups->findById( $groupId );
		if ( ! $group ) {
			throw new \InvalidArgumentException( 'Группа не найдена.' );
		}
		if ( AccessMode::Open !== AccessMode::fromValueOrDefault( (string) ( $group->access_mode ?? '' ) ) ) {
			throw new \InvalidArgumentException( 'Добавлять существующих учеников можно только в открытые группы.' );
		}

		$courseId    = (int) ( $group->course_id ?? 0 );
		$courseTitle = ( $courseId > 0 ? $this->courses->get( $courseId )?->title : null ) ?: (string) $group->name;

		$added   = 0;
		$skipped = 0;
		foreach ( array_unique( array_filter( array_map( 'intval', $studentPersonIds ) ) ) as $studentPersonId ) {
			if ( null === $this->enrollOne( $studentPersonId, $groupId, $actorUserId ) ) {
				++$skipped;
				continue;
			}
			$this->notify( $studentPersonId, $courseTitle );
			++$added;
		}

		return array( 'added' => $added, 'skipped' => $skipped );
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

	/**
	 * @return int|null ID созданной записи; null — пропущен (дубль или у ученика нет записей).
	 */
	private function enrollOne( int $studentPersonId, int $groupId, int $actorUserId ): ?int {
		if ( $this->studentRecords->existsActive( $studentPersonId, $groupId ) ) {
			return null;
		}

		// Родитель и снапшоты ФИО/школы — из последней записи ученика; документы не нужны (NULL).
		$latest = $this->studentRecords->findByStudent( $studentPersonId )[0] ?? null;
		if ( null === $latest ) {
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

		// Инвариант «запись ⇒ событие»: журнал аудита + generic-хук для модулей.
		$this->logEvents->dispatch(
			LogEvent::StudentEnrolled,
			new EnrollmentStatusEvent( $actorUserId, AuditAction::EnrollStudent, $studentPersonId, $recordId, $groupId )
		);
		do_action( 'fs_lms_student_enrolled', $recordId, $studentPersonId );

		return $recordId;
	}
}
