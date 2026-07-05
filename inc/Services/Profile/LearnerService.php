<?php

declare( strict_types=1 );

namespace Inc\Services\Profile;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\Enums\Wp\PageRoutes;
use Inc\Managers\Course\CourseManager;
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
		private readonly CourseManager           $courses,
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
					'course_id'   => isset( $g->course_id ) ? (int) $g->course_id : 0,
					'teacher_id'  => isset( $g->teacher_id ) ? (int) $g->teacher_id : 0,
					'access_mode' => (string) ( $g->access_mode ?? 'scheduled' ), // Эпик 15: открытая группа
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
			'courses'   => $this->buildCourses( $groups, $rawRows, $lessonMap, $roomNames ),
			// Эпик 15 (П10): каталог открытых курсов для самозаписи.
			'catalog'   => $this->buildCatalog( array_keys( $groups ) ),
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

	/**
	 * Каталог открытых курсов для самозаписи (Эпик 15, П10): открытые группы с
	 * назначенным курсом, в которых ученик ещё не состоит.
	 *
	 * @param int[] $memberGroupIds ID групп, где ученик уже активен.
	 * @return array<int, array<string, mixed>>
	 */
	private function buildCatalog( array $memberGroupIds ): array {
		$catalog = array();
		foreach ( $this->groups->findOpen() as $g ) {
			$gid      = (int) $g->id;
			$courseId = (int) ( $g->course_id ?? 0 );
			if ( $courseId <= 0 || in_array( $gid, $memberGroupIds, true ) ) {
				continue;
			}
			$course = $this->courses->get( $courseId );
			if ( null === $course ) {
				continue;
			}
			$subjectName = $this->subjects->getByKey( $g->subject_key )?->name ?? $g->subject_key;
			$catalog[]   = array(
				'group_id'      => $gid,
				'title'         => '' !== $course->title ? $course->title : $subjectName,
				'subject'       => $subjectName,
				'subject_key'   => (string) $g->subject_key,
				'abbr'          => $this->subjectAbbr( $subjectName ),
				'teacher'       => ! empty( $g->teacher_id ) ? ( get_userdata( (int) $g->teacher_id )->display_name ?? '' ) : '',
				'lessons_total' => count( $course->lessonIds() ),
			);
		}

		return $catalog;
	}

	/**
	 * Курсы ученика для экрана «Мои курсы» (по одной группе): заголовок/предмет/
	 * преподаватель/кабинет + структура модулей курса, сопоставленная с
	 * запланированными занятиями ученика (статус/дата/ссылка в плеер). Урок курса,
	 * ещё не запланированный ученику, — закрыт. Курс без модулей — плоский список
	 * фактически запланированных занятий по дате.
	 *
	 * @param array<int, array<string,mixed>> $groups
	 * @param array<int, GroupLessonDTO>      $rawRows   glid → строка
	 * @param array<int, array<string,mixed>> $lessonMap glid → item занятия
	 * @param array<int, string>              $roomNames id → имя кабинета
	 * @return array<int, array<string, mixed>>
	 */
	private function buildCourses( array $groups, array $rawRows, array $lessonMap, array $roomNames ): array {
		// lessonId → item ученика, по группе (групповые занятия с контентом).
		$byLesson = array();
		foreach ( $rawRows as $glid => $row ) {
			if ( 'individual' === $row->kind || null === $row->lessonId || 0 === (int) $row->lessonId ) {
				continue;
			}
			$byLesson[ $row->groupId ][ (int) $row->lessonId ] = $lessonMap[ $glid ] ?? null;
		}

		$result = array();
		foreach ( $groups as $gid => $g ) {
			$course   = $g['course_id'] > 0 ? $this->courses->get( $g['course_id'] ) : null;
			$roomName = $g['room_id'] > 0 ? ( $roomNames[ $g['room_id'] ] ?? '' ) : '';
			$teacher  = $g['teacher_id'] > 0 ? ( get_userdata( $g['teacher_id'] )->display_name ?? '' ) : '';
			$map      = $byLesson[ $gid ] ?? array();

			$modules = array();
			$num     = 0;
			if ( null !== $course && ! empty( $course->modules ) ) {
				foreach ( $course->modules as $mi => $module ) {
					$modLessons = array();
					foreach ( $module->lessonIds as $lessonId ) {
						++$num;
						$modLessons[] = $this->courseLessonItem( $num, (int) $lessonId, $map[ (int) $lessonId ] ?? null, $roomName, (int) $mi );
					}
					$modules[] = array( 'name' => $module->title, 'lessons' => $modLessons );
				}
			}

			// Плоский список (курс без модулей): фактически запланированные занятия ученика.
			$flatLessons = array();
			if ( empty( $modules ) ) {
				$rows = array_values( array_filter(
					$lessonMap,
					static fn( $it ) => $it['group_id'] === $gid && 'individual' !== $it['kind']
				) );
				usort( $rows, static fn( $a, $b ) => strcmp( (string) $a['scheduled_at'], (string) $b['scheduled_at'] ) );
				foreach ( $rows as $i => $it ) {
					$flatLessons[] = array(
						'num'        => $i + 1,
						'title'      => '' !== (string) $it['topic'] ? $it['topic'] : 'Занятие ' . ( $i + 1 ),
						'date'       => $it['date'],
						'room'       => '' !== (string) $it['room'] ? $it['room'] : $roomName,
						'status'     => '' !== (string) $it['status'] ? $it['status'] : 'locked',
						'player_url' => $it['player_url'],
						'mod'        => null,
					);
				}
			}

			$all    = ! empty( $modules ) ? array_merge( ...array_column( $modules, 'lessons' ) ) : $flatLessons;
			$total  = count( $all );
			$passed = count( array_filter( $all, static fn( $l ) => 'done' === $l['status'] ) );

			$next  = null;
			$start = '';
			foreach ( $all as $l ) {
				if ( null === $next && 'available' === $l['status'] && '' !== (string) $l['player_url'] ) {
					$next = $l;
				}
				if ( '' !== (string) $l['date'] && ( '' === $start || $l['date'] < $start ) ) {
					$start = $l['date'];
				}
			}

			$result[] = array(
				'id'           => $gid,
				'code'         => $g['name'],
				'open'         => 'open' === ( $g['access_mode'] ?? 'scheduled' ), // Эпик 15: бейдж «свободное прохождение»
				'title'        => null !== $course && '' !== $course->title ? $course->title : $g['subject'],
				'subject'      => $g['subject'],
				'subject_key'  => $g['subject_key'], // ключ цвета чипа (chipIndex, utils.js)
				'abbr'         => $this->subjectAbbr( $g['subject'] ),
				'teacher'      => $teacher,
				'room'         => $roomName,
				'start'        => $start,
				'modules'      => ! empty( $modules ) ? $modules : null,
				'lessons'      => ! empty( $modules ) ? null : $flatLessons,
				'passed'       => $passed,
				'total'        => $total,
				'not_started'  => $total > 0 && 0 === $passed && null === $next,
				'continue_url' => $next['player_url'] ?? '',
				'continue_num' => $next['num'] ?? 0,
			);
		}

		return $result;
	}

	/**
	 * Один урок курса для экрана «Мои курсы»: из запланированного занятия ученика,
	 * либо (если ещё не запланирован) закрытый по названию банк-урока.
	 *
	 * @param array<string,mixed>|null $item
	 * @return array<string, mixed>
	 */
	private function courseLessonItem( int $num, int $lessonId, ?array $item, string $roomName, int $modIdx ): array {
		if ( null !== $item ) {
			return array(
				'num'        => $num,
				'title'      => '' !== (string) $item['topic'] ? $item['topic'] : ( $this->lessons->get( $lessonId )?->topic ?? 'Урок ' . $num ),
				'date'       => $item['date'],
				'room'       => '' !== (string) $item['room'] ? $item['room'] : $roomName,
				'status'     => '' !== (string) $item['status'] ? $item['status'] : 'locked',
				'player_url' => $item['player_url'],
				'mod'        => $modIdx,
			);
		}

		return array(
			'num'        => $num,
			'title'      => $this->lessons->get( $lessonId )?->topic ?? 'Урок ' . $num,
			'date'       => '',
			'room'       => $roomName,
			'status'     => 'locked',
			'player_url' => '',
			'mod'        => $modIdx,
		);
	}

	/** Аббревиатура предмета для чипа вкладки курса (первые 3 буквы, верхний регистр). */
	private function subjectAbbr( string $subject ): string {
		$s = trim( $subject );
		return '' === $s ? '—' : mb_strtoupper( mb_substr( $s, 0, 3 ) );
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
