<?php

declare( strict_types=1 );

namespace Inc\Services\Group;

use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\GroupLessonInputDTO;
use Inc\Enums\Course\LessonKind;
use Inc\Managers\Course\CourseManager;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\RoomRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;

/**
 * Class IndividualLessonService
 *
 * Индивидуальные занятия группы (D3): занятие на одного ученика, привязанное
 * к дате, вне программы группы и вне раскладки reflow.
 *
 * @package Inc\Services\Group
 *
 * Программа группы — {@see ProgramCompositionService}; общий календарь —
 * {@see GroupCalendarService}.
 */
readonly class IndividualLessonService {

	/**
	 * @param GroupLessonRepository   $groupLessons  Строки программы/занятий
	 * @param GroupsRepository        $groups        Группы
	 * @param StudentRecordRepository $records       Записи учеников (членство в группе)
	 * @param RoomRepository          $rooms         Кабинеты
	 * @param LessonManager           $lessonManager Банк уроков
	 * @param CourseManager           $courses       Курсы (уроки назначенного курса)
	 * @param ScheduleEventPublisher  $events        Публикация событий обучения
	 */
	public function __construct(
		private GroupLessonRepository   $groupLessons,
		private GroupsRepository        $groups,
		private StudentRecordRepository $records,
		private RoomRepository          $rooms,
		private LessonManager           $lessonManager,
		private CourseManager           $courses,
		private ScheduleEventPublisher  $events,
	) {}

	/**
	 * Создаёт индивидуальное занятие на одного ученика (D3): `kind='individual'`,
	 * привязано к дате (`is_pinned`), НЕ входит в программу группы и НЕ участвует
	 * в раскладке `reflow`.
	 *
	 * @param int         $groupId         ID группы
	 * @param int         $studentPersonId Ученик (обязан состоять в группе)
	 * @param string      $scheduledAt     'Y-m-d H:i:s'
	 * @param string|null $endsAt          'Y-m-d H:i:s' (опц.)
	 * @param int|null    $lessonId        Привязка к банку урока (опц.)
	 * @param string|null $label           Ярлык строки (опц.)
	 * @param int|null    $teacherUserId   Явный преподаватель (опц., иначе — препод группы)
	 * @param int         $actorUserId     Автор изменения
	 * @param int|null    $roomId          Кабинет (опц.)
	 *
	 * @return int ID созданного занятия
	 *
	 * @throws \InvalidArgumentException Если группа не найдена или ученик не в группе
	 */
	public function createIndividualLesson(
		int     $groupId,
		int     $studentPersonId,
		string  $scheduledAt,
		?string $endsAt,
		?int    $lessonId,
		?string $label,
		?int    $teacherUserId,
		int     $actorUserId,
		?int    $roomId = null
	): int {
		if ( ! $this->groups->findById( $groupId ) ) {
			throw new \InvalidArgumentException( 'Группа не найдена.' );
		}

		$this->assertMember( $groupId, $studentPersonId );

		$id = $this->groupLessons->add( new GroupLessonInputDTO(
			groupId         : $groupId,
			lessonId        : $lessonId,
			position        : 0,
			scheduledAt     : $scheduledAt,
			endsAt          : $endsAt,
			isPinned        : true,
			teacherUserId   : $teacherUserId,
			createdByUserId : $actorUserId,
			label           : $label,
			kind            : LessonKind::Individual,
			status          : 'scheduled',
			studentPersonId : $studentPersonId,
			roomId          : $roomId,
		) );

		$this->events->lessonChanged( $groupId, $id, $actorUserId );

		return $id;
	}

	/**
	 * НБ-9: индивидуальные занятия группы для режима КТП «Индивидуальные занятия».
	 * Каждый слот: ФИО ученика, дата/время, эффективный кабинет, привязанный урок
	 * (тема) либо пусто. Порядок — по дате.
	 *
	 * @param int $groupId ID группы
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getIndividualProgram( int $groupId ): array {
		$group = $this->groups->findById( $groupId );
		if ( ! $group ) {
			return array();
		}

		$names = array();
		foreach ( $this->records->findActiveByGroupId( $groupId ) as $rec ) {
			$names[ $rec->studentPersonId ] = trim( $rec->snapshotLastName . ' ' . $rec->snapshotFirstName );
		}

		$roomNames = array();
		foreach ( $this->rooms->findAll() as $r ) {
			$roomNames[ $r->id ] = $r->name;
		}
		$groupRoomId = ( ! empty( $group->room_id ) ) ? (int) $group->room_id : 0;

		$items = array();
		foreach ( $this->groupLessons->listByGroup( $groupId ) as $row ) {
			if ( ! $row->kind->isIndividual() || null === $row->studentPersonId ) {
				continue;
			}

			$lesson    = $row->lessonId ? $this->lessonManager->get( $row->lessonId ) : null;
			$effRoomId = ! empty( $row->roomId ) ? (int) $row->roomId : $groupRoomId;
			$items[]   = array(
				'group_lesson_id'   => $row->id,
				'group_id'          => $groupId,
				'group_name'        => $group->name,
				'subject'           => $group->subject_key,
				'student_person_id' => $row->studentPersonId,
				'student_name'      => $names[ $row->studentPersonId ] ?? '—',
				'scheduled_at'      => $row->scheduledAt,
				'ends_at'           => $row->endsAt, // B2: время окончания (префилл правки)
				'room'              => ( $effRoomId && isset( $roomNames[ $effRoomId ] ) ) ? $roomNames[ $effRoomId ] : '',
				'room_id'           => ! empty( $row->roomId ) ? (int) $row->roomId : 0, // B2: для префилла правки
				'lesson_id'         => $row->lessonId,
				'topic'             => $lesson?->topic ?? ( $row->label ?? '' ),
			);
		}

		usort( $items, static fn( $a, $b ) => strcmp( (string) $a['scheduled_at'], (string) $b['scheduled_at'] ) );

		return $items;
	}

	/**
	 * НБ-9: уроки предмета группы для назначения индивидуальному занятию — сперва
	 * уроки назначенного курса (`in_course`), затем остальные уроки предмета;
	 * фильтр по названию.
	 *
	 * @param int    $groupId ID группы
	 * @param string $search  Фильтр по названию урока
	 *
	 * @return array<int, array{id:int, title:string, in_course:bool}>
	 */
	public function lessonCandidatesForGroup( int $groupId, string $search = '' ): array {
		$group = $this->groups->findById( $groupId );
		if ( ! $group ) {
			return array();
		}

		$courseLessonIds = array();
		if ( ! empty( $group->course_id ) ) {
			$course = $this->courses->get( (int) $group->course_id );
			if ( $course ) {
				foreach ( $course->lessonIds() as $lid ) {
					$courseLessonIds[ (int) $lid ] = true;
				}
			}
		}

		$args = array( 'limit' => 100 );
		if ( '' !== $search ) {
			$args['search'] = $search;
		}

		$out = array();
		foreach ( $this->lessonManager->getBankBySubject( (string) $group->subject_key, $args ) as $lesson ) {
			$out[] = array(
				'id'        => $lesson->id,
				'title'     => $lesson->topic,
				'in_course' => isset( $courseLessonIds[ (int) $lesson->id ] ),
			);
		}

		// Уроки курса — первыми (стабильная сортировка сохраняет исходный порядок внутри групп).
		usort( $out, static fn( $a, $b ) => ( $b['in_course'] <=> $a['in_course'] ) );

		return $out;
	}

	/**
	 * НБ-9: привязывает урок банка к индивидуальному занятию (`group_lessons.lesson_id`).
	 *
	 * @param int $groupLessonId ID индивидуального занятия
	 * @param int $lessonId      ID урока банка
	 * @param int $actorUserId   Автор изменения
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException Если строка не найдена/не индивидуальная или урок не найден
	 */
	public function assignLessonToIndividual( int $groupLessonId, int $lessonId, int $actorUserId ): void {
		$row = $this->requireIndividual( $groupLessonId );
		$this->assertLessonExists( $lessonId );

		$this->groupLessons->setLessonId( $groupLessonId, $lessonId );
		$this->events->groupChanged( $row->groupId, $actorUserId );
	}

	/**
	 * Правка индивидуального занятия (B2): дата/время, кабинет, ученик, урок (тема).
	 * null-поля не меняются. Новый ученик должен состоять в группе занятия.
	 *
	 * @param int         $groupLessonId   ID индивидуального занятия
	 * @param string|null $scheduledAt     Новое начало
	 * @param string|null $endsAt          Новое окончание
	 * @param int|null    $roomId          Новый кабинет (0 — снять)
	 * @param int|null    $studentPersonId Новый ученик
	 * @param int|null    $lessonId        Новый урок банка
	 * @param int         $actorUserId     Автор изменения
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException Если занятие не найдено, ученик не в группе или урок не найден
	 */
	public function updateIndividualLesson(
		int     $groupLessonId,
		?string $scheduledAt,
		?string $endsAt,
		?int    $roomId,
		?int    $studentPersonId,
		?int    $lessonId,
		int     $actorUserId
	): void {
		$row = $this->requireIndividual( $groupLessonId );

		if ( null !== $scheduledAt && '' !== $scheduledAt ) {
			$this->groupLessons->updateSchedule( $groupLessonId, $scheduledAt, $row->teacherUserId, $endsAt );
		}

		if ( null !== $roomId ) {
			$this->groupLessons->setRoom( $groupLessonId, $roomId > 0 ? $roomId : null );
		}

		if ( null !== $studentPersonId && $studentPersonId > 0 ) {
			$this->assertMember( $row->groupId, $studentPersonId );
			$this->groupLessons->setStudentPersonId( $groupLessonId, $studentPersonId );
		}

		if ( null !== $lessonId && $lessonId > 0 ) {
			$this->assertLessonExists( $lessonId );
			$this->groupLessons->setLessonId( $groupLessonId, $lessonId );
		}

		$this->events->groupChanged( $row->groupId, $actorUserId );
	}

	/**
	 * Индивидуальное занятие или исключение.
	 *
	 * @param int $groupLessonId ID занятия
	 *
	 * @throws \InvalidArgumentException Если занятие не найдено или не индивидуальное
	 */
	private function requireIndividual( int $groupLessonId ): GroupLessonDTO {
		$row = $this->groupLessons->find( $groupLessonId );
		if ( ! $row || ! $row->kind->isIndividual() ) {
			throw new \InvalidArgumentException( 'Индивидуальное занятие не найдено.' );
		}

		return $row;
	}

	/**
	 * Проверяет, что ученик состоит в группе.
	 *
	 * @param int $groupId         ID группы
	 * @param int $studentPersonId ID ученика
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException Если ученик не в группе
	 */
	private function assertMember( int $groupId, int $studentPersonId ): void {
		foreach ( $this->records->findActiveByGroupId( $groupId ) as $rec ) {
			if ( $rec->studentPersonId === $studentPersonId ) {
				return;
			}
		}

		throw new \InvalidArgumentException( 'Ученик не состоит в этой группе.' );
	}

	/**
	 * Проверяет существование урока банка.
	 *
	 * @param int $lessonId ID урока
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException Если урок не найден
	 */
	private function assertLessonExists( int $lessonId ): void {
		if ( null === $this->lessonManager->get( $lessonId ) ) {
			throw new \InvalidArgumentException( 'Урок не найден.' );
		}
	}
}
