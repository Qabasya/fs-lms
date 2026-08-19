<?php

declare( strict_types=1 );

namespace Inc\Services\Profile;

use DateTimeImmutable;
use Inc\Contracts\ClockInterface;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\WorkDTO;
use Inc\Enums\Profile\NotificationType;
use Inc\Enums\Wp\PageRoutes;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\NotificationRepository;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;
use Inc\Services\Course\EffectiveWorksResolver;
use Inc\Services\Group\SessionCalendarService;

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
		private SessionCalendarService   $calendar,
	) {}

	public function tick(): void {
		$this->lessonSoon();
		$this->lessonOpened();
		$this->deadlines();
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
	 * Уроки вне расписания (Этап 4, Tasks.md), открывшиеся ученикам лениво: занятия
	 * в этот день нет — `LessonSoon` не отправлялся (плановой встречи не было), и
	 * без этого уведомления ученик о новом уроке не узнает никак. Плановые занятия
	 * не дублируем — LessonSoon уже предупредил за 30 минут.
	 *
	 * `visibility` в БД не переписывается автопереходом hidden→open (тот ленивый,
	 * только на чтение), поэтому окно смотрит назад с запасом, а не «ровно этот
	 * тик» — устойчиво к пропущенным прогонам WP-Cron; дубли гасит `dedupe_key`.
	 */
	private function lessonOpened(): void {
		$now   = $this->clock->now();
		$since = $this->shift( $now, '-24 hours' );

		foreach ( $this->groupLessons->listRecentlyOpened( $since, $now ) as $lesson ) {
			if ( ! $this->isOffSchedule( $lesson ) ) {
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

	/** День занятия не входит в штатное расписание группы (Этап 4: урок вне расписания). */
	private function isOffSchedule( GroupLessonDTO $lesson ): bool {
		if ( null === $lesson->scheduledAt ) {
			return false;
		}
		$lessonDays = $this->calendar->periodMeta( $lesson->groupId )['lessonDays'];

		return ! in_array( substr( $lesson->scheduledAt, 0, 10 ), $lessonDays, true );
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

	private function purge(): void {
		$this->notificationRepository->purge( 30, 90 );
	}

	/** Наивный сдвиг wall-clock строки (без конвертации часовых поясов) — паттерн {@see \Inc\Services\Group\ScheduleService}. */
	private function shift( string $datetime, string $modify ): string {
		return ( new DateTimeImmutable( $datetime ) )->modify( $modify )->format( 'Y-m-d H:i:s' );
	}
}
