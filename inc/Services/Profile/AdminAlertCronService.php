<?php

declare( strict_types=1 );

namespace Inc\Services\Profile;

use DateTimeImmutable;
use Inc\Contracts\ClockInterface;
use Inc\Enums\Profile\NotificationType;
use Inc\Enums\Wp\PageRoutes;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\SubstitutionRepository;
use Inc\Services\Person\PresenceService;

/**
 * Class AdminAlertCronService
 *
 * Временны́е уведомления администратору платформы — тот же тик, что у
 * {@see NotificationCronService} (раз в 15 минут). Условия пунктов «журнал»,
 * «не сдаёт работы», «не проверена» — из {@see AdminAlertService} (их же видит
 * блок «Требует внимания»); здесь — окна отправки и «Преподавателя нет на месте».
 * Серию пропусков администратор получает сразу по отметке
 * ({@see \Inc\Services\Course\AttendanceService}). Окна с запасом на пропущенные
 * тики WP-Cron, повторы гасит `dedupe_key`.
 *
 * @package Inc\Services\Profile
 */
class AdminAlertCronService {

	/** Занятие должно идти хотя бы столько, прежде чем отсутствие преподавателя — повод тревожить. */
	private const ABSENT_GRACE = '-15 minutes';

	/** Появление в системе незадолго до начала занятия тоже считается присутствием. */
	private const PRESENT_BEFORE = '-15 minutes';

	public function __construct(
		private readonly AdminAlertService      $alerts,
		private readonly NotificationService    $notifications,
		private readonly GroupLessonRepository  $groupLessons,
		private readonly SubstitutionRepository $substitutions,
		private readonly PresenceService        $presence,
		private readonly ClockInterface         $clock,
	) {}

	public function tick(): void {
		$admins = $this->notifications->adminUserIds();
		if ( empty( $admins ) ) {
			return;
		}

		$this->journalOverdue( $admins );
		$this->homeworkStreaks( $admins );
		$this->reviewOverdue( $admins );
		$this->teacherAbsent( $admins );
	}

	/** Спустя сутки после окончания занятия журнал не заполнен. @param int[] $admins */
	private function journalOverdue( array $admins ): void {
		$due = $this->shift( $this->clock->now(), AdminAlertService::JOURNAL_GRACE );

		foreach ( $this->alerts->overdueJournals( $this->shift( $due, '-24 hours' ), $due ) as $j ) {
			$lesson = $j['lesson'];
			$this->notifications->push(
				$admins,
				NotificationType::JournalOverdue,
				"journal_admin:{$lesson->id}",
				array(
					'teacher_name' => $j['teacher_name'],
					'topic'        => $j['topic'],
					'group_name'   => $j['group_name'],
				),
				$this->screenUrl( 'journal' ),
				$lesson->groupId,
				'group_lesson',
				$lesson->id
			);
		}
	}

	/**
	 * Серия несданных ДЗ. Только свежие — последний срок серии прошёл за последние
	 * сутки: давняя серия уже была отправлена (ключ — первая работа серии, её рост
	 * новой плитки не даёт), а при первом запуске не всплывает весь архив.
	 *
	 * @param int[] $admins
	 */
	private function homeworkStreaks( array $admins ): void {
		$since = $this->shift( $this->clock->now(), '-24 hours' );

		foreach ( $this->alerts->homeworkStreaks() as $h ) {
			if ( $h['latest_due'] < $since ) {
				continue;
			}
			$this->notifications->push(
				$admins,
				NotificationType::HomeworkStreak,
				"hw_streak:{$h['person_id']}:{$h['group_id']}:{$h['first_key']}",
				array(
					'student_name' => $h['student_name'],
					'count'        => $h['count'],
					'group_name'   => $h['group_name'],
				),
				$this->screenUrl( 'summary' ),
				$h['group_id']
			);
		}
	}

	/** Работа ждёт проверки 48 часов после уведомления преподавателю. @param int[] $admins */
	private function reviewOverdue( array $admins ): void {
		$due = $this->shift( $this->clock->now( 'mysql', true ), AdminAlertService::REVIEW_GRACE );

		foreach ( $this->alerts->overdueReviews( $this->shift( $due, '-24 hours' ), $due ) as $r ) {
			$this->notifications->push(
				$admins,
				NotificationType::ReviewOverdue,
				"review_admin:{$r['submission_id']}",
				array(
					'teacher_name' => $r['teacher_name'],
					'student_name' => $r['student_name'],
					'topic'        => $r['topic'],
					'group_name'   => $r['group_name'],
				),
				$this->screenUrl( 'works' ),
				$r['group_id'],
				'submission',
				$r['submission_id']
			);
		}
	}

	/**
	 * Занятие идёт 15 минут ({@see self::ABSENT_GRACE}), а преподаватель с
	 * {@see self::PRESENT_BEFORE} до начала так и не появился в системе, и замена
	 * на дату не назначена. Преподаватель, о котором ещё нет ни одной отметки
	 * присутствия (учёт появился недавно), не проверяется — это не отсутствие,
	 * а отсутствие данных.
	 *
	 * @param int[] $admins
	 */
	private function teacherAbsent( array $admins ): void {
		$now = $this->clock->now();

		foreach ( $this->groupLessons->listStartingBetween( $this->shift( $now, '-24 hours' ), $this->shift( $now, self::ABSENT_GRACE ) ) as $lesson ) {
			if ( null === $lesson->scheduledAt || $this->notifications->isOpenGroup( $lesson->groupId ) ) {
				continue;
			}
			if ( null !== $this->substitutions->findActiveForGroup( $lesson->groupId, substr( $lesson->scheduledAt, 0, 10 ) ) ) {
				continue;
			}
			if ( empty( $this->notifications->lessonStudentPersonIds( $lesson ) ) ) {
				continue;
			}

			$teacherId = $this->notifications->lessonTeacherUserId( $lesson );
			if ( null === $teacherId ) {
				continue;
			}
			$lastSeen = $this->presence->lastSeenAt( $teacherId );
			if ( null === $lastSeen || $lastSeen >= $this->shift( $lesson->scheduledAt, self::PRESENT_BEFORE ) ) {
				continue;
			}

			$this->notifications->push(
				$admins,
				NotificationType::TeacherAbsent,
				"teacher_absent:{$lesson->id}",
				array(
					'teacher_name' => $this->notifications->userDisplayName( $teacherId ),
					'topic'        => $this->notifications->lessonTopic( $lesson ),
					'group_name'   => $this->notifications->groupName( $lesson->groupId ),
				),
				$this->screenUrl( 'substitutions' ),
				$lesson->groupId,
				'group_lesson',
				$lesson->id
			);
		}
	}

	private function screenUrl( string $screen ): string {
		return (string) add_query_arg( array( 'screen' => $screen ), PageRoutes::UserProfile->url() );
	}

	private function shift( string $datetime, string $modify ): string {
		return ( new DateTimeImmutable( $datetime ) )->modify( $modify )->format( 'Y-m-d H:i:s' );
	}
}
