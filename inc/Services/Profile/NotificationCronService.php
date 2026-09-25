<?php

declare( strict_types=1 );

namespace Inc\Services\Profile;

use DateTimeImmutable;
use Inc\Contracts\ClockInterface;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\WorkDTO;
use Inc\Enums\Course\LessonStatus;
use Inc\Enums\Course\LessonVisibility;
use Inc\Enums\Course\WorkType;
use Inc\Enums\Profile\NotificationType;
use Inc\Enums\Wp\PageRoutes;
use Inc\Repositories\WPDBRepositories\AttendanceRepository;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\NotificationRepository;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;
use Inc\Services\Course\EffectiveWorksResolver;
use Inc\Services\Course\LessonVisibilityService;

/**
 * Class NotificationCronService
 *
 * Временны́е продюсеры in-app уведомлений — вызывается раз в 15 минут
 * (`CronHook::NotificationsTick`, {@see \Inc\Controllers\System\CronController}).
 * Окна устойчивы к пропущенным тикам WP-Cron: каждый прогон смотрит назад/вперёд
 * с запасом, дубли гасит `dedupe_key` (UNIQUE per получатель — не убегает при
 * повторном тике и не задваивается между «скоро»/«просрочено»-проходами).
 *
 * `scheduled_at`/`homework_due_at`/`work_deadlines` хранятся местным wall-clock
 * временем сайта (как и everywhere в Course-сервисах) — {@see ClockInterface::now()}
 * с параметрами по умолчанию возвращает время в той же системе отсчёта, поэтому
 * сравнение строк корректно без конвертаций часовых поясов.
 *
 * @package Inc\Services\Profile
 */
readonly class NotificationCronService {

	public function __construct(
		private GroupLessonRepository   $groupLessons,
		private SubmissionRepository     $submissions,
		private EffectiveWorksResolver   $worksResolver,
		private NotificationRepository   $notificationRepository,
		private NotificationService      $notifications,
		private ClockInterface           $clock,
		private LessonVisibilityService  $visibility,
		private AttendanceRepository     $attendance,
	) {}

	public function tick(): void {
		$this->lessonSoon();
		$this->lessonOpened();
		$this->deadlines();
		$this->homeworkBeforeNextLesson();
		$this->nextLessonBegan();
		$this->absenceMarked();
		$this->journalNotFilled();
		$this->purge();
	}

	/** Занятия, начинающиеся через (0, 30] минут — ученикам и эффективному учителю. */
	private function lessonSoon(): void {
		$now  = $this->clock->now();
		$soon = $this->shift( $now, '+30 minutes' );

		foreach ( $this->groupLessons->listStartingBetween( $now, $soon ) as $lesson ) {
			$recipients = $this->notifications->lessonStudentUserIds( $lesson );
			$teacherId  = $this->notifications->lessonTeacherUserId( $lesson );
			if ( null !== $teacherId ) {
				$recipients[] = $teacherId;
			}
			if ( empty( $recipients ) ) {
				continue;
			}

			$this->notifications->push(
				array_unique( $recipients ),
				NotificationType::LessonSoon,
				"lesson_soon:{$lesson->id}",
				array(
					'topic'      => $this->notifications->lessonTopic( $lesson ),
					'group_name' => $this->notifications->groupName( $lesson->groupId ),
					'time'       => $lesson->scheduledAt ? substr( $lesson->scheduledAt, 11, 5 ) : '',
				),
				$lesson->lessonId
					? PageRoutes::LessonPlayer->lessonUrl( $lesson->groupId, $lesson->id )
					: PageRoutes::UserProfile->url(),
				$lesson->groupId,
				'group_lesson',
				$lesson->id
			);
		}
	}

	/**
	 * Открыт новый урок — ученикам: по дате занятия (ленивое открытие), вручную
	 * или сразу при появлении в открытой группе. Урок-черновик по дате не
	 * открывается — о нём не уведомляем.
	 *
	 * `visibility` в БД не переписывается автопереходом hidden→open (тот ленивый,
	 * только на чтение), поэтому окно смотрит назад с запасом, а не «ровно этот
	 * тик» — устойчиво к пропущенным прогонам WP-Cron; дубли гасит `dedupe_key`.
	 */
	private function lessonOpened(): void {
		$now   = $this->clock->now();
		$since = $this->shift( $now, '-24 hours' );

		foreach ( $this->groupLessons->listRecentlyOpened( $since, $now ) as $lesson ) {
			if ( LessonVisibility::Open->value !== $this->visibility->effectiveVisibility( $lesson ) ) {
				continue;
			}

			$recipients = $this->notifications->lessonStudentUserIds( $lesson );
			if ( empty( $recipients ) ) {
				continue;
			}

			$this->notifications->push(
				array_unique( $recipients ),
				NotificationType::LessonOpened,
				"opened:{$lesson->id}",
				array(
					'topic'      => $this->notifications->lessonTopic( $lesson ),
					'group_name' => $this->notifications->groupName( $lesson->groupId ),
				),
				$lesson->lessonId
					? PageRoutes::LessonPlayer->lessonUrl( $lesson->groupId, $lesson->id )
					: PageRoutes::UserProfile->url(),
				$lesson->groupId,
				'group_lesson',
				$lesson->id
			);
		}
	}

	/**
	 * Per-work дедлайны занятий с открытой видимостью: приближающиеся (ученикам без
	 * сдачи) и пропущенные (ученикам без сдачи + их родителям). Состав работ — как в
	 * {@see \Inc\Services\Profile\LearnerService::deadlines()}.
	 */
	private function deadlines(): void {
		$now          = $this->clock->now();
		$soonWindow   = $this->shift( $now, '+24 hours' );
		$missedWindow = $this->shift( $now, '-24 hours' );

		foreach ( $this->groupLessons->listWithDeadlines() as $lesson ) {
			$studentPersonIds = $this->notifications->lessonStudentPersonIds( $lesson );
			if ( empty( $studentPersonIds ) ) {
				continue;
			}

			$works = $this->worksResolver->resolve( $lesson );
			if ( empty( $works ) ) {
				continue;
			}

			$submittedByStudent = array();
			foreach ( $studentPersonIds as $studentPersonId ) {
				$submittedByStudent[ $studentPersonId ] = array_map(
					static fn( $s ) => $s->workId,
					$this->submissions->listByStudentAndGroupLesson( $studentPersonId, $lesson->id )
				);
			}

			foreach ( $works as $work ) {
				$due = $lesson->deadlineForWork( $work->id );
				if ( null === $due ) {
					continue;
				}

				$pending = array_values( array_filter(
					$studentPersonIds,
					static fn( int $id ): bool => ! in_array( $work->id, $submittedByStudent[ $id ], true )
				) );
				if ( empty( $pending ) ) {
					continue;
				}

				if ( $due > $now && $due <= $soonWindow ) {
					$this->notifyDeadline( NotificationType::DeadlineSoon, 'dl_soon', $lesson, $work, $pending, false );
				} elseif ( $due >= $missedWindow && $due < $now ) {
					$this->notifyDeadline( NotificationType::DeadlineMissed, 'dl_miss', $lesson, $work, $pending, true );
				}
			}
		}
	}

	/**
	 * @param int[] $pendingStudentPersonIds Ученики без сдачи этой работы (уже отфильтровано)
	 */
	private function notifyDeadline(
		NotificationType $type,
		string           $dedupePrefix,
		GroupLessonDTO   $lesson,
		WorkDTO          $work,
		array            $pendingStudentPersonIds,
		bool             $includeGuardians
	): void {
		$recipients = array();
		foreach ( $pendingStudentPersonIds as $personId ) {
			$userId = $this->notifications->studentUserId( $personId );
			if ( null !== $userId ) {
				$recipients[] = $userId;
			}
			if ( $includeGuardians ) {
				array_push( $recipients, ...$this->notifications->guardianUserIds( $personId ) );
			}
		}
		if ( empty( $recipients ) ) {
			return;
		}

		$this->notifications->push(
			array_unique( $recipients ),
			$type,
			"{$dedupePrefix}:{$lesson->id}:{$work->id}",
			array(
				'topic'      => $work->title,
				'group_name' => $this->notifications->groupName( $lesson->groupId ),
			),
			$this->notifications->lessonWorkUrl( $lesson, $work->id ),
			$lesson->groupId,
			'group_lesson',
			$lesson->id
		);
	}

	/**
	 * Домашняя работа без явного дедлайна сдаётся к следующему занятию: за 24 часа
	 * до его начала — «скоро сдача» ученикам, которые её ещё не сдали. Работы с
	 * явным дедлайном идут своим путём ({@see deadlines()}).
	 */
	private function homeworkBeforeNextLesson(): void {
		$now = $this->clock->now();

		foreach ( $this->groupLessons->listStartingBetween( $now, $this->shift( $now, '+24 hours' ) ) as $next ) {
			if ( $next->kind->isIndividual() ) {
				continue;
			}
			$previous = $this->previousLesson( $next );
			if ( null === $previous ) {
				continue;
			}

			foreach ( $this->homeworkDueAtNextLesson( $previous ) as $work ) {
				$pending = $this->studentsWithoutSubmission( $previous, $work->id );
				if ( ! empty( $pending ) ) {
					$this->notifyDeadline( NotificationType::DeadlineSoon, 'dl_soon', $previous, $work, $pending, false );
				}
			}
		}
	}

	/**
	 * Началось следующее занятие — домашняя работа прошлого без явного дедлайна
	 * не сдана: «пропущена сдача» ученику и родителю.
	 */
	private function nextLessonBegan(): void {
		$now = $this->clock->now();

		foreach ( $this->groupLessons->listGroupBeganBetween( $this->shift( $now, '-24 hours' ), $now ) as $next ) {
			$previous = $this->previousLesson( $next );
			if ( null === $previous ) {
				continue;
			}

			foreach ( $this->homeworkDueAtNextLesson( $previous ) as $work ) {
				$pending = $this->studentsWithoutSubmission( $previous, $work->id );
				if ( ! empty( $pending ) ) {
					$this->notifyDeadline( NotificationType::DeadlineMissed, 'dl_miss', $previous, $work, $pending, true );
				}
			}
		}
	}

	/**
	 * Через час после Н в журнале — «пропущено занятие» ученику и родителю.
	 * Час — на исправление ошибочной отметки: снятая или исправленная на «был»
	 * Н в выборку уже не попадает, а отправленное позже отзывает
	 * {@see \Inc\Services\Course\AttendanceService}. `marked_at` хранится в GMT,
	 * поэтому окно считается в GMT; запас в сутки — на пропущенные тики WP-Cron,
	 * дубли гасит `dedupe_key`.
	 */
	private function absenceMarked(): void {
		$nowGmt = $this->clock->now( 'mysql', true );
		$marks  = $this->attendance->listAbsentMarkedBetween( $this->shift( $nowGmt, '-25 hours' ), $this->shift( $nowGmt, '-1 hour' ) );

		$lessons = array();
		foreach ( $marks as $mark ) {
			if ( ! array_key_exists( $mark->groupLessonId, $lessons ) ) {
				$lessons[ $mark->groupLessonId ] = $this->groupLessons->find( $mark->groupLessonId );
			}
			$lesson = $lessons[ $mark->groupLessonId ];
			if ( null === $lesson ) {
				continue;
			}
			$this->notifyMissedLesson( $lesson, $mark->studentPersonId );
		}
	}

	private function notifyMissedLesson( GroupLessonDTO $lesson, int $personId ): void {
		$payload = array(
			'student_name' => $this->notifications->studentSnapshotName( $personId, $lesson->groupId ),
			'topic'        => $this->notifications->lessonTopic( $lesson ),
			'group_name'   => $this->notifications->groupName( $lesson->groupId ),
		);
		$dedupe = "att:{$lesson->id}:{$personId}";

		$studentUserId = $this->notifications->studentUserId( $personId );
		if ( null !== $studentUserId ) {
			$this->notifications->push(
				array( $studentUserId ),
				NotificationType::AttendanceMissed,
				$dedupe,
				$payload,
				PageRoutes::LessonPlayer->lessonUrl( $lesson->groupId, $lesson->id ),
				$lesson->groupId,
				'group_lesson',
				$lesson->id
			);
		}

		$this->notifications->push(
			$this->notifications->guardianUserIds( $personId ),
			NotificationType::AttendanceMissed,
			$dedupe,
			$payload,
			(string) add_query_arg( array( 'screen' => 'learner-attendance' ), PageRoutes::UserProfile->url() ),
			$lesson->groupId,
			'group_lesson',
			$lesson->id
		);
	}

	/**
	 * Через час после окончания занятия посещаемость не отмечена — преподавателю.
	 * Открытые группы журнал посещаемости не ведут; группа без учеников — не повод.
	 */
	private function journalNotFilled(): void {
		$now = $this->clock->now();

		foreach ( $this->groupLessons->listGroupEndedBetween( $this->shift( $now, '-25 hours' ), $this->shift( $now, '-1 hour' ) ) as $lesson ) {
			if ( $lesson->hasAttendance || $this->notifications->isOpenGroup( $lesson->groupId ) ) {
				continue;
			}
			if ( empty( $this->notifications->lessonStudentPersonIds( $lesson ) ) ) {
				continue;
			}

			$teacherUserId = $this->notifications->lessonTeacherUserId( $lesson );
			if ( null === $teacherUserId ) {
				continue;
			}

			$this->notifications->push(
				array( $teacherUserId ),
				NotificationType::JournalNotFilled,
				"journal:{$lesson->id}",
				array(
					'topic'      => $this->notifications->lessonTopic( $lesson ),
					'group_name' => $this->notifications->groupName( $lesson->groupId ),
				),
				(string) add_query_arg( array( 'screen' => 'journal' ), PageRoutes::UserProfile->url() ),
				$lesson->groupId,
				'group_lesson',
				$lesson->id
			);
		}
	}

	/**
	 * Прошлое групповое занятие перед `$next`: последнее по дате, не отменённое
	 * и не перенесённое.
	 */
	private function previousLesson( GroupLessonDTO $next ): ?GroupLessonDTO {
		$previous = null;
		foreach ( $this->groupLessons->listByGroup( $next->groupId ) as $row ) {
			if (
				$row->kind->isIndividual()
				|| null === $row->scheduledAt
				|| $row->scheduledAt >= (string) $next->scheduledAt
				|| LessonStatus::fromValueOrDefault( $row->status )->freesSlot()
			) {
				continue;
			}
			if ( null === $previous || $row->scheduledAt > $previous->scheduledAt ) {
				$previous = $row;
			}
		}

		return $previous;
	}

	/**
	 * Домашние работы занятия, которые сдаются к следующему занятию: без явного
	 * дедлайна и только если урок открыт ученикам.
	 *
	 * @return WorkDTO[]
	 */
	private function homeworkDueAtNextLesson( GroupLessonDTO $lesson ): array {
		if ( LessonVisibility::Hidden->value === $this->visibility->effectiveVisibility( $lesson ) ) {
			return array();
		}

		return array_values( array_filter(
			$this->worksResolver->resolve( $lesson ),
			static fn( WorkDTO $w ): bool => WorkType::Homework === $w->workType && null === $lesson->deadlineForWork( $w->id )
		) );
	}

	/** @return int[] Person id учеников занятия, не сдавших работу. */
	private function studentsWithoutSubmission( GroupLessonDTO $lesson, int $workId ): array {
		return array_values( array_filter(
			$this->notifications->lessonStudentPersonIds( $lesson ),
			fn( int $personId ): bool => ! in_array(
				$workId,
				array_map( static fn( $s ) => $s->workId, $this->submissions->listByStudentAndGroupLesson( $personId, $lesson->id ) ),
				true
			)
		) );
	}

	private function purge(): void {
		$this->notificationRepository->purge( 30, 90 );
	}

	/** Наивный сдвиг wall-clock строки (без конвертации часовых поясов) — паттерн {@see \Inc\Services\Group\ScheduleService}. */
	private function shift( string $datetime, string $modify ): string {
		return ( new DateTimeImmutable( $datetime ) )->modify( $modify )->format( 'Y-m-d H:i:s' );
	}
}
