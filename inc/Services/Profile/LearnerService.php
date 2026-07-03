<?php

declare( strict_types=1 );

namespace Inc\Services\Profile;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\Enums\Wp\PageRoutes;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\AttendanceRepository;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\RoomRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;
use Inc\Services\Course\EffectiveWorksResolver;
use Inc\Services\Course\GradebookService;
use Inc\Services\Course\LessonGateResolver;
use Inc\Services\Course\LessonProgressService;

/**
 * Read-модель профиля учащегося (Эпик 7). Собирает по одному `student_person_id`
 * (ученик — свой, родитель — ребёнка): группы, расписание/дедлайны, дневник
 * (сырые баллы, D4) и посещаемость (бинарно + %). Только чтение.
 *
 * @package Inc\Services\Profile
 */
class LearnerService {

	public function __construct(
		private readonly StudentRecordRepository $records,
		private readonly GroupsRepository        $groups,
		private readonly GroupLessonRepository   $groupLessons,
		private readonly LessonManager           $lessons,
		private readonly GradebookService        $gradebook,
		private readonly AttendanceRepository    $attendance,
		private readonly ClockInterface          $clock,
		private readonly SubmissionRepository    $submissions,
		private readonly EffectiveWorksResolver  $worksResolver,
		private readonly LessonGateResolver      $gate,
		private readonly LessonProgressService   $progress,
		private readonly SubjectRepository       $subjects,
		private readonly RoomRepository          $rooms,
	) {}

	/** @return array<string, mixed> */
	public function build( int $personId ): array {
		$now = $this->clock->now( 'mysql' );

		// #14: карта кабинетов id→имя (для «Каб N» в расписании). Один запрос на всё.
		$roomNames = array();
		foreach ( $this->rooms->findAll() as $roomDto ) {
			$roomNames[ (int) $roomDto->id ] = $roomDto->name;
		}

		// Группы ученика.
		$groups   = array();
		$groupIds = array();
		foreach ( $this->records->findActiveByStudent( $personId ) as $rec ) {
			if ( isset( $groups[ $rec->groupId ] ) ) {
				continue;
			}
			$g = $this->groups->findById( $rec->groupId );
			if ( $g ) {
				// #12: человекочитаемое название предмета вместо слага (fallback — слаг).
				$subjectName             = $this->subjects->getByKey( $g->subject_key )?->name ?? $g->subject_key;
				$groups[ $rec->groupId ] = array(
					'id'          => (int) $g->id,
					'name'        => $g->name,
					'subject'     => $subjectName,
					'subject_key' => $g->subject_key,
					'room_id'     => isset( $g->room_id ) ? (int) $g->room_id : 0, // дефолтный кабинет группы
				);
				$groupIds[]              = (int) $g->id;
			}
		}

		// Карта занятий групп ученика (+ его индивидуальные).
		$lessonMap = array();
		$allLessons = array();
		$rawRows    = array(); // T12.2: сырые строки — нужны для per-work дедлайнов ниже.
		foreach ( $groupIds as $gid ) {
			$gname = $groups[ $gid ]['name'];
			foreach ( $this->groupLessons->listByGroup( $gid ) as $row ) {
				if ( 'individual' === $row->kind && $row->studentPersonId !== $personId ) {
					continue;
				}
				$topic      = $this->topicOf( $row );
				$hasContent = null !== $row->lessonId && 0 !== $row->lessonId;
				// #14: эффективный кабинет занятия = кабинет строки ?? дефолтный кабинет группы.
				$roomId     = ! empty( $row->roomId ) ? (int) $row->roomId : (int) ( $groups[ $gid ]['room_id'] ?? 0 );
				$item       = array(
					'group_lesson_id' => $row->id,
					'group_id'        => $gid,
					'group_name'      => $gname,
					'topic'           => $topic,
					'date'            => $row->scheduledAt ? substr( $row->scheduledAt, 0, 10 ) : '',
					'start'           => $row->scheduledAt ? substr( $row->scheduledAt, 11, 5 ) : '',
					'scheduled_at'    => $row->scheduledAt,
					'homework_due_at' => $row->homeworkDueAt,
					'visibility'      => $row->visibility,
					'kind'            => $row->kind,
					'room'            => $roomId > 0 ? ( $roomNames[ $roomId ] ?? '' ) : '',
					// Вход в плеер курса (T14.13): урок с контентом получает ссылку
					// в плеер и статус прохождения (done / available / locked).
					'player_url'      => $hasContent ? $this->playerUrl( $gid, $row->id ) : '',
					'status'          => $hasContent ? $this->lessonStatus( $personId, $row ) : '',
				);
				$lessonMap[ $row->id ] = $item;
				$allLessons[]          = $item;
				$rawRows[ $row->id ]   = $row;
			}
		}

		// Расписание (ближайшие).
		$upcoming = array();
		foreach ( $allLessons as $l ) {
			if ( $l['scheduled_at'] && $l['scheduled_at'] >= $now ) {
				$upcoming[] = $l;
			}
		}
		usort( $upcoming, static fn( $a, $b ) => strcmp( (string) $a['scheduled_at'], (string) $b['scheduled_at'] ) );
		usort( $allLessons, static fn( $a, $b ) => strcmp( (string) $b['scheduled_at'], (string) $a['scheduled_at'] ) );

		// Дедлайны работ (T12.2, D13): per-work дедлайн (иначе legacy homeworkDueAt занятия).
		// Прошедшие НЕ скрываем — помечаем overdue (решать всё равно можно, hard cutoff нет).
		// Уже сданные работы — не напоминаем.
		$deadlines = array();
		foreach ( $rawRows as $glid => $row ) {
			$submittedWorkIds = array();
			foreach ( $this->submissions->listByStudentAndGroupLesson( $personId, $glid ) as $sub ) {
				$submittedWorkIds[ $sub->workId ] = true;
			}
			foreach ( $this->worksResolver->resolve( $row ) as $work ) {
				if ( isset( $submittedWorkIds[ $work->id ] ) ) {
					continue;
				}
				$due = $row->deadlineForWork( $work->id );
				if ( null === $due ) {
					continue;
				}
				$deadlines[] = array(
					'due_at'     => $due,
					'topic'      => $work->title,
					'group_name' => $lessonMap[ $glid ]['group_name'],
					'overdue'    => $due < $now,
				);
			}
		}
		usort( $deadlines, static fn( $a, $b ) => strcmp( $a['due_at'], $b['due_at'] ) );

		// Дневник (сырые баллы).
		$grades = array();
		foreach ( $this->gradebook->forStudent( $personId ) as $e ) {
			$grades[] = array(
				'title'      => $e->title,
				'category'   => $e->category,
				'value'      => $e->displayValue(),
				'display'    => $e->displayType,
				'graded_at'  => $e->gradedAt,
				'group_name' => $groups[ $e->groupId ]['name'] ?? '',
			);
		}
		$recent = array_values( array_filter( $grades, static fn( $g ) => ! empty( $g['graded_at'] ) ) );
		usort( $recent, static fn( $a, $b ) => strcmp( (string) $b['graded_at'], (string) $a['graded_at'] ) );

		// Посещаемость (бинарно + %).
		$rows    = array();
		$present = 0;
		$total   = 0;
		foreach ( $this->attendance->listByStudent( $personId ) as $a ) {
			$l = $lessonMap[ $a->groupLessonId ] ?? null;
			$rows[] = array(
				'date'    => $l ? $l['date'] : substr( $a->markedAt, 0, 10 ),
				'topic'   => $l ? $l['topic'] : '—',
				'present' => $a->isPresent,
			);
			++$total;
			if ( $a->isPresent ) {
				++$present;
			}
		}
		usort( $rows, static fn( $a, $b ) => strcmp( (string) $b['date'], (string) $a['date'] ) );

		return array(
			'groups'    => array_values( $groups ),
			'upcoming'  => array_slice( $upcoming, 0, 6 ),
			'deadlines' => array_slice( $deadlines, 0, 6 ),
			'recent'    => array_slice( $recent, 0, 5 ),
			'lessons'   => $allLessons,
			'grades'    => $grades,
			'attendance' => array(
				'rows'    => $rows,
				'present' => $present,
				'total'   => $total,
				'percent' => $total > 0 ? (int) round( $present / $total * 100 ) : null,
			),
		);
	}

	private function topicOf( GroupLessonDTO $row ): string {
		$lesson = $row->lessonId ? $this->lessons->get( $row->lessonId ) : null;
		return $lesson?->topic ?? ( $row->label ?? '' );
	}

	/** Deep-link в плеер курса: маршрут кокпита группы + ?gl=. */
	private function playerUrl( int $groupId, int $groupLessonId ): string {
		return add_query_arg(
			array(
				'gid' => $groupId,
				'gl'  => $groupLessonId,
			),
			PageRoutes::GroupCockpit->url()
		);
	}

	/** Статус занятия для «Мои курсы»: пройден / доступен / закрыт (T14.13). */
	private function lessonStatus( int $personId, GroupLessonDTO $row ): string {
		if ( $this->progress->isLessonCompleted( $personId, $row->id ) ) {
			return 'done';
		}

		return $this->gate->resolveLesson( $personId, $row )->isAvailable() ? 'available' : 'locked';
	}
}
