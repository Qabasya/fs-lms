<?php

declare( strict_types=1 );

namespace Inc\Services\Profile\Learner;

use Inc\DTO\Course\GroupLessonDTO;
use Inc\Managers\Course\CourseManager;
use Inc\Managers\Course\LessonManager;
use Inc\Services\Assessment\ExamLockService;
use Inc\Services\Course\LessonProgressService;

/**
 * Class LearnerCoursesSection
 *
 * Секция кабинета ученика: «Мои курсы» (структура модулей, статусы уроков,
 * recency-сортировка) и гейт активной контрольной (ExamLockService).
 *
 * Выделена из LearnerService (Т14.3).
 *
 * @package Inc\Services\Profile\Learner
 */
class LearnerCoursesSection {

	public function __construct(
		private readonly CourseManager         $courses,
		private readonly LessonManager         $lessons,
		private readonly LessonProgressService $progress,
		private readonly ExamLockService       $examLock,
		private readonly LearnerContextBuilder $contextBuilder,
	) {}

	/**
	 * Активная контрольная блокирует ВЕСЬ контент (ExamLockService).
	 *
	 * Отдаём явно, чтобы кабинет писал «Недоступно, пока идёт контрольная» + ссылку
	 * на неё, а не вводящее в заблуждение «курс стартует / откроется по дате».
	 *
	 * @param int $personId Физлицо ученика
	 *
	 * @return array{title: string, url: string}|null
	 */
	public function examLock( int $personId ): ?array {
		$lockAttempt = $this->examLock->getActiveLockingAttempt( $personId );

		if ( null === $lockAttempt ) {
			return null;
		}

		return array(
			'title' => get_the_title( $lockAttempt->assessmentId ) ?: 'Экзамен',
			'url'   => (string) get_permalink( $lockAttempt->assessmentId ),
		);
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
	public function buildCourses( array $groups, array $rawRows, array $lessonMap, array $roomNames, int $personId ): array {
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
				'title'        => $this->contextBuilder->courseTitleForGroup( $g ),
				'subject'      => $g['subject'],
				'subject_key'  => $g['subject_key'], // ключ цвета чипа (chipIndex, utils.js)
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

		// №1 (D17.1): recency-сортировка — курс с недавно открытым уроком первым
		// (MAX(lesson_progress.updated_at) по группе). Курсы без активности идут
		// после, сохраняя порядок зачисления (usort стабилен в PHP 8+). Пустой
		// timestamp сортируется ниже любого реального времени.
		$activity = $this->progress->latestActivityByStudent( $personId );
		usort( $result, static function ( array $a, array $b ) use ( $activity ): int {
			$ta = $activity[ (int) $a['id'] ] ?? '';
			$tb = $activity[ (int) $b['id'] ] ?? '';
			return strcmp( $tb, $ta );
		} );

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
}
