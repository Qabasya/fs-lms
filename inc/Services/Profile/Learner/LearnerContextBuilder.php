<?php

declare( strict_types=1 );

namespace Inc\Services\Profile\Learner;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Profile\LearnerContextDTO;
use Inc\Enums\Wp\PageRoutes;
use Inc\Managers\Course\CourseManager;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\RoomRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Course\EffectiveTeacherResolver;
use Inc\Services\Course\LessonGateResolver;
use Inc\Services\Course\LessonProgressService;

/**
 * Class LearnerContextBuilder
 *
 * Общий контекст кабинета ученика (LearnerContextDTO): группы, их занятия
 * (+ индивидуальные ученика), карта кабинетов. Читается ОДИН раз на построение
 * кабинета и переиспользуется всеми секциями.
 *
 * Выделен из LearnerService (Т14.3); секции — Learner*Section, фасад — LearnerService.
 *
 * @package Inc\Services\Profile\Learner
 */
class LearnerContextBuilder {

	public function __construct(
		private readonly StudentRecordRepository  $records,
		private readonly GroupsRepository         $groups,
		private readonly GroupLessonRepository    $groupLessons,
		private readonly LessonManager            $lessons,
		private readonly CourseManager            $courses,
		private readonly SubjectRepository        $subjects,
		private readonly RoomRepository           $rooms,
		private readonly ClockInterface           $clock,
		private readonly EffectiveTeacherResolver $effectiveTeacher,
		private readonly LessonProgressService    $progress,
		private readonly LessonGateResolver       $gate,
	) {}

	/**
	 * Читает всё, что нужно нескольким секциям сразу: группы ученика, их занятия
	 * (+ его индивидуальные) и карту кабинетов.
	 *
	 * @param int $personId Физлицо ученика
	 */
	public function build( int $personId ): LearnerContextDTO {
		$now = $this->clock->now( 'mysql' );

		// #14: карта кабинетов id→имя (для «Каб N» в расписании). Один запрос на всё.
		$roomNames = array();
		foreach ( $this->rooms->findAll() as $roomDto ) {
			$roomNames[ (int) $roomDto->id ] = $roomDto->name;
		}

		$groups = $this->groupCards( $personId );

		$lessonMap    = array();
		$allLessons   = array();
		$rawRows      = array(); // T12.2: сырые строки — нужны для per-work дедлайнов.
		$teacherNames = array(); // кэш id→display_name на всё построение.

		foreach ( array_keys( $groups ) as $gid ) {
			foreach ( $this->groupLessons->listByGroup( $gid ) as $row ) {
				// Чужие индивидуальные занятия ученику не показываем.
				if ( 'individual' === $row->kind && $row->studentPersonId !== $personId ) {
					continue;
				}

				$item                  = $this->lessonCard( $row, $groups[ $gid ], $personId, $roomNames, $teacherNames );
				$lessonMap[ $row->id ] = $item;
				$allLessons[]          = $item;
				$rawRows[ $row->id ]   = $row;
			}
		}

		usort( $allLessons, static fn( $a, $b ) => strcmp( (string) $b['scheduled_at'], (string) $a['scheduled_at'] ) );

		return new LearnerContextDTO( $groups, $lessonMap, $allLessons, $rawRows, $roomNames, $now );
	}

	/**
	 * Название курса группы (fallback — название предмета). Единый источник для
	 * вкладки «Мои курсы» (#15: чип) и посещаемости (#14: подпись занятия).
	 *
	 * @param array<string, mixed> $g
	 */
	public function courseTitleForGroup( array $g ): string {
		$course = ( (int) ( $g['course_id'] ?? 0 ) ) > 0 ? $this->courses->get( (int) $g['course_id'] ) : null;
		return ( null !== $course && '' !== $course->title ) ? $course->title : (string) ( $g['subject'] ?? '' );
	}

	/**
	 * Карточки групп ученика (id → данные группы).
	 *
	 * @param int $personId Физлицо ученика
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function groupCards( int $personId ): array {
		$groups = array();

		foreach ( $this->records->findActiveByStudent( $personId ) as $rec ) {
			if ( isset( $groups[ $rec->groupId ] ) ) {
				continue;
			}

			$g = $this->groups->findById( $rec->groupId );
			if ( ! $g ) {
				continue;
			}

			$groups[ $rec->groupId ] = array(
				'id'          => (int) $g->id,
				'name'        => $g->name,
				// #12: человекочитаемое название предмета вместо слага (fallback — слаг).
				'subject'     => $this->subjects->getByKey( $g->subject_key )?->name ?? $g->subject_key,
				'subject_key' => $g->subject_key,
				'room_id'     => isset( $g->room_id ) ? (int) $g->room_id : 0, // дефолтный кабинет группы
				'course_id'   => isset( $g->course_id ) ? (int) $g->course_id : 0,
				'teacher_id'  => isset( $g->teacher_id ) ? (int) $g->teacher_id : 0,
				'access_mode' => (string) ( $g->access_mode ?? 'scheduled' ), // Эпик 15: открытая группа
			);
			// #14: название курса группы (тот же текст, что во вкладке «Мои курсы»).
			$groups[ $rec->groupId ]['course_title'] = $this->courseTitleForGroup( $groups[ $rec->groupId ] );
		}

		return $groups;
	}

	/**
	 * Карточка занятия для расписания и списка уроков.
	 *
	 * @param GroupLessonDTO       $row          Строка программы
	 * @param array<string, mixed> $group        Карточка группы
	 * @param int                  $personId     Физлицо ученика
	 * @param array<int, string>   $roomNames    Кабинеты id → название
	 * @param array<int, string>   $teacherNames Кэш преподавателей id → имя (по ссылке)
	 *
	 * @return array<string, mixed>
	 */
	private function lessonCard( GroupLessonDTO $row, array $group, int $personId, array $roomNames, array &$teacherNames ): array {
		$hasContent = null !== $row->lessonId && 0 !== $row->lessonId;
		// #14: эффективный кабинет занятия = кабинет строки ?? дефолтный кабинет группы.
		$roomId = ! empty( $row->roomId ) ? (int) $row->roomId : (int) ( $group['room_id'] ?? 0 );
		// Фактический препод занятия (Эпик 5, D5): разовый override › замена › препод группы.
		$teacherId = $this->effectiveTeacher->forLesson( $row );
		if ( $teacherId && ! isset( $teacherNames[ $teacherId ] ) ) {
			$teacherNames[ $teacherId ] = get_userdata( $teacherId )->display_name ?? '';
		}

		return array(
			'group_lesson_id' => $row->id,
			'group_id'        => (int) $group['id'],
			'group_name'      => $group['name'],
			'topic'           => $this->topicOf( $row ),
			'date'            => $row->scheduledAt ? substr( $row->scheduledAt, 0, 10 ) : '',
			'start'           => $row->scheduledAt ? substr( $row->scheduledAt, 11, 5 ) : '',
			'scheduled_at'    => $row->scheduledAt,
			'homework_due_at' => $row->homeworkDueAt,
			'visibility'      => $row->visibility,
			'kind'            => $row->kind,
			'room'            => $roomId > 0 ? ( $roomNames[ $roomId ] ?? '' ) : '',
			'teacher'         => $teacherId ? ( $teacherNames[ $teacherId ] ?? '' ) : '',
			'course'          => (string) ( $group['course_title'] ?? '' ),
			// Вход в плеер курса (T14.13): урок с контентом получает ссылку
			// в плеер и статус прохождения (done / available / locked).
			'player_url'      => $hasContent ? PageRoutes::LessonPlayer->lessonUrl( (int) $group['id'], $row->id ) : '',
			'status'          => $hasContent ? $this->lessonStatus( $personId, $row ) : '',
		);
	}

	private function topicOf( GroupLessonDTO $row ): string {
		$lesson = $row->lessonId ? $this->lessons->get( $row->lessonId ) : null;
		return $lesson?->topic ?? ( $row->label ?? '' );
	}

	/** Статус занятия для «Мои курсы»: пройден / доступен / закрыт (T14.13). */
	private function lessonStatus( int $personId, GroupLessonDTO $row ): string {
		if ( $this->progress->isLessonCompleted( $personId, $row->id ) ) {
			return 'done';
		}

		return $this->gate->resolveLesson( $personId, $row )->isAvailable() ? 'available' : 'locked';
	}
}
