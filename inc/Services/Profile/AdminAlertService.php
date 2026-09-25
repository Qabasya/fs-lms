<?php

declare( strict_types=1 );

namespace Inc\Services\Profile;

use DateTimeImmutable;
use Inc\Contracts\ClockInterface;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\Enums\Course\AccessMode;
use Inc\Enums\Course\SubmissionStatus;
use Inc\Enums\Profile\NotificationType;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\NotificationRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;
use Inc\Services\Course\AttendanceService;
use Inc\Services\Course\HomeworkDeadlineService;

/**
 * Class AdminAlertService
 *
 * Сигналы администратору платформы — что требует вмешательства:
 * - журнал занятия не заполнен спустя сутки после его окончания;
 * - ученик пропустил подряд {@see AttendanceService::ADMIN_ABSENCE_STREAK} занятия;
 * - ученик подряд не сдал {@see self::HOMEWORK_STREAK} домашние работы;
 * - работа ждёт проверки 48 часов после «Сдана работа — нужна проверка».
 *
 * Одна read-модель на два потребителя: крон уведомлений
 * ({@see AdminAlertCronService}) и блок «Требует внимания» на главной
 * администратора ({@see DashboardService}) — иначе плитка и строка на главной
 * расходились бы по условиям. Открытые группы журнал и сроки не ведут — пропускаются.
 *
 * @package Inc\Services\Profile
 */
class AdminAlertService {

	/** Третья подряд домашняя работа, не сданная к сроку, — уже сигнал администратору. */
	public const HOMEWORK_STREAK = 3;

	/** Журнал не заполнен спустя столько после окончания занятия. */
	public const JOURNAL_GRACE = '-24 hours';

	/** Работа не проверена спустя столько после уведомления преподавателю. */
	public const REVIEW_GRACE = '-48 hours';

	/** Главная: насколько назад смотреть незаполненные журналы и непроверенные работы. */
	private const DASHBOARD_LOOKBACK = '-30 days';

	public function __construct(
		private readonly GroupsRepository        $groups,
		private readonly GroupLessonRepository   $groupLessons,
		private readonly StudentRecordRepository $records,
		private readonly SubmissionRepository    $submissions,
		private readonly NotificationRepository  $notificationRepository,
		private readonly AttendanceService       $attendance,
		private readonly HomeworkDeadlineService $homework,
		private readonly NotificationService     $notifications,
		private readonly ClockInterface          $clock,
	) {}

	/**
	 * Занятия, закончившиеся в окне [from, to), без единой отметки посещаемости.
	 *
	 * @return array<int, array{lesson: GroupLessonDTO, teacher_user_id: ?int, teacher_name: string, topic: string, group_name: string}>
	 */
	public function overdueJournals( string $from, string $to ): array {
		$out = array();
		foreach ( $this->groupLessons->listGroupEndedBetween( $from, $to ) as $lesson ) {
			if ( $lesson->hasAttendance || $this->notifications->isOpenGroup( $lesson->groupId ) ) {
				continue;
			}
			if ( empty( $this->notifications->lessonStudentPersonIds( $lesson ) ) ) {
				continue;
			}

			$teacherId = $this->notifications->lessonTeacherUserId( $lesson );
			$out[]     = array(
				'lesson'          => $lesson,
				'teacher_user_id' => $teacherId,
				'teacher_name'    => null !== $teacherId ? $this->notifications->userDisplayName( $teacherId ) : '',
				'topic'           => $this->notifications->lessonTopic( $lesson ),
				'group_name'      => $this->notifications->groupName( $lesson->groupId ),
			);
		}

		return $out;
	}

	/**
	 * Текущие серии пропусков от {@see AttendanceService::ADMIN_ABSENCE_STREAK} занятий.
	 *
	 * @return array<int, array{person_id: int, group_id: int, student_name: string, group_name: string, count: int, first_lesson_id: int}>
	 */
	public function absenceStreaks(): array {
		$out = array();
		foreach ( $this->activeGroups() as $group ) {
			$names   = $this->studentNames( (int) $group->id );
			$streaks = $this->attendance->absenceStreaks( (int) $group->id );
			foreach ( $streaks as $personId => $lessonIds ) {
				if ( ! isset( $names[ $personId ] ) || count( $lessonIds ) < AttendanceService::ADMIN_ABSENCE_STREAK ) {
					continue;
				}
				$out[] = array(
					'person_id'       => $personId,
					'group_id'        => (int) $group->id,
					'student_name'    => $names[ $personId ],
					'group_name'      => (string) $group->name,
					'count'           => count( $lessonIds ),
					'first_lesson_id' => (int) end( $lessonIds ),
				);
			}
		}

		return $out;
	}

	/**
	 * Текущие серии несданных домашних работ от {@see self::HOMEWORK_STREAK}. Считаются
	 * ДЗ со сроком после зачисления ученика: пришедшему в середине курса прошлые
	 * работы в серию не идут. Сданная хоть с опозданием работа серию прерывает.
	 *
	 * @return array<int, array{person_id: int, group_id: int, student_name: string, group_name: string, count: int, first_key: string, latest_due: string}>
	 */
	public function homeworkStreaks(): array {
		$now = $this->clock->now();
		$out = array();

		foreach ( $this->activeGroups() as $group ) {
			$groupId = (int) $group->id;
			$records = $this->records->findActiveByGroupId( $groupId );
			if ( empty( $records ) ) {
				continue;
			}

			$rows = array_values( array_filter(
				$this->groupLessons->listByGroup( $groupId ),
				static fn( GroupLessonDTO $row ): bool => ! $row->kind->isIndividual()
			) );
			$due  = $this->homework->dueHomework( $rows, $now );
			if ( count( $due ) < self::HOMEWORK_STREAK ) {
				continue;
			}

			$submitted = array();
			foreach ( $this->submissions->listForGradebookByGroup( $groupId ) as $sub ) {
				$submitted[ $sub->studentPersonId ][ $sub->groupLessonId ][ $sub->workId ] = true;
			}

			foreach ( $records as $rec ) {
				$streak = array();
				foreach ( array_reverse( $due ) as $item ) {
					if ( $item['due_at'] < $rec->enrolledAt || isset( $submitted[ $rec->studentPersonId ][ $item['row']->id ][ $item['work']->id ] ) ) {
						break;
					}
					$streak[] = $item;
				}
				if ( count( $streak ) < self::HOMEWORK_STREAK ) {
					continue;
				}

				$first = end( $streak );
				$out[] = array(
					'person_id'    => $rec->studentPersonId,
					'group_id'     => $groupId,
					'student_name' => trim( "{$rec->snapshotLastName} {$rec->snapshotFirstName}" ),
					'group_name'   => (string) $group->name,
					'count'        => count( $streak ),
					'first_key'    => "{$first['row']->id}:{$first['work']->id}",
					'latest_due'   => $streak[0]['due_at'],
				);
			}
		}

		return $out;
	}

	/**
	 * Работы, о которых преподаватель получил «Сдана работа — нужна проверка» в
	 * окне [from, to) и которые до сих пор не проверены (не оценены и не возвращены).
	 *
	 * @return array<int, array{submission_id: int, group_id: ?int, teacher_name: string, student_name: string, topic: string, group_name: string, notified_at: string}>
	 */
	public function overdueReviews( string $fromGmt, string $toGmt ): array {
		$out = array();
		foreach ( $this->notificationRepository->listByTypeCreatedBetween( NotificationType::ReviewNeeded->value, $fromGmt, $toGmt ) as $n ) {
			$subId = (int) $n->entityId;
			if ( $subId <= 0 || isset( $out[ $subId ] ) ) {
				continue;
			}
			$sub = $this->submissions->find( $subId );
			if ( null === $sub || ! in_array( $sub->status, array( SubmissionStatus::Submitted, SubmissionStatus::PendingReview ), true ) ) {
				continue;
			}

			$out[ $subId ] = array(
				'submission_id' => $subId,
				'group_id'      => $n->groupId,
				'teacher_name'  => $this->notifications->userDisplayName( $n->recipientUserId ),
				'student_name'  => (string) ( $n->payload['student_name'] ?? '' ),
				'topic'         => (string) ( $n->payload['topic'] ?? '' ),
				'group_name'    => (string) ( $n->payload['group_name'] ?? '' ),
				'notified_at'   => $n->createdAt,
			);
		}

		return array_values( $out );
	}

	/**
	 * Строки блока «Требует внимания» главной администратора.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function forDashboard(): array {
		$now    = $this->clock->now();
		$nowGmt = $this->clock->now( 'mysql', true );
		$rows   = array();

		foreach ( $this->overdueJournals( $this->shift( $now, self::DASHBOARD_LOOKBACK ), $this->shift( $now, self::JOURNAL_GRACE ) ) as $j ) {
			$rows[] = array(
				'kind'         => 'journal',
				'group_id'     => $j['lesson']->groupId,
				'group_name'   => $j['group_name'],
				'teacher_name' => $j['teacher_name'],
				'topic'        => $j['topic'],
				'date'         => substr( (string) $j['lesson']->scheduledAt, 0, 10 ),
			);
		}

		foreach ( $this->absenceStreaks() as $a ) {
			$rows[] = array( 'kind' => 'absence' ) + $a;
		}

		foreach ( $this->homeworkStreaks() as $h ) {
			$rows[] = array( 'kind' => 'homework' ) + $h;
		}

		foreach ( $this->overdueReviews( $this->shift( $nowGmt, self::DASHBOARD_LOOKBACK ), $this->shift( $nowGmt, self::REVIEW_GRACE ) ) as $r ) {
			$rows[] = array( 'kind' => 'review' ) + $r;
		}

		return $rows;
	}

	/** Группы, где ведутся журнал и сроки: не удалённые и не открытые. */
	private function activeGroups(): array {
		return array_values( array_filter(
			$this->groups->findAll(),
			static fn( object $g ): bool => empty( $g->deleted_at )
				&& AccessMode::Open !== AccessMode::fromValueOrDefault( (string) ( $g->access_mode ?? '' ) )
		) );
	}

	/** @return array<int, string> person id → снапшот-имя активных учеников группы */
	private function studentNames( int $groupId ): array {
		$names = array();
		foreach ( $this->records->findActiveByGroupId( $groupId ) as $rec ) {
			$names[ $rec->studentPersonId ] = trim( "{$rec->snapshotLastName} {$rec->snapshotFirstName}" );
		}

		return $names;
	}

	private function shift( string $datetime, string $modify ): string {
		return ( new DateTimeImmutable( $datetime ) )->modify( $modify )->format( 'Y-m-d H:i:s' );
	}
}
