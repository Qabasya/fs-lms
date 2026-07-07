<?php

declare( strict_types=1 );

namespace Inc\Services\Group;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Course\GroupLessonInputDTO;
use Inc\DTO\Log\Events\LearningEvent;
use Inc\Enums\Log\LogEvent;
use Inc\Managers\Course\CourseManager;
use Inc\Managers\Course\LessonManager;
use Inc\Services\Course\RoomAvailabilityService;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\RoomRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;

class ScheduleService {

	public function __construct(
		private readonly GroupLessonRepository       $groupLessons,
		private readonly LessonManager               $lessonManager,
		private readonly GroupsRepository            $groups,
		private readonly LogEventDispatcherInterface $dispatcher,
		private readonly SessionCalendarService      $calendar,
		private readonly StudentRecordRepository     $records,
		private readonly RoomRepository              $rooms,
		private readonly RoomAvailabilityService     $roomAvailability,
		private readonly CourseManager               $courses,
	) {}

	/**
	 * Свободные кабинеты для группы в окне занятия (T11.3): фильтр по предмету группы
	 * + отсутствие конфликта времени. Конец окна — `$end` или +60 мин от начала.
	 *
	 * @return array<int, array{id:int, name:string}>
	 */
	public function freeRoomsForGroup( int $groupId, string $start, ?string $end = null ): array {
		$group = $this->groups->findById( $groupId );
		if ( ! $group ) {
			return array();
		}
		$endWindow = ( $end && '' !== $end )
			? $end
			: ( new \DateTimeImmutable( $start ) )->modify( '+60 minutes' )->format( 'Y-m-d H:i:s' );

		return array_map(
			static fn( $room ): array => array( 'id' => $room->id, 'name' => $room->name ),
			$this->roomAvailability->listFreeRooms( $start, $endWindow, (string) $group->subject_key )
		);
	}

	/**
	 * Создаёт индивидуальное занятие на одного ученика (D3): `kind='individual'`,
	 * привязано к дате (`is_pinned`), НЕ входит в программу группы и НЕ участвует
	 * в раскладке `reflow`.
	 *
	 * @param string      $scheduledAt   'Y-m-d H:i:s'.
	 * @param string|null $endsAt        'Y-m-d H:i:s' (опц.).
	 * @param int|null    $lessonId      привязка к банку урока (опц.).
	 * @param string|null $label         ярлык строки (опц.).
	 * @param int|null    $teacherUserId явный преподаватель (опц., иначе — препод группы).
	 *
	 * @throws \InvalidArgumentException если группа не найдена или ученик не в группе.
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
		$group = $this->groups->findById( $groupId );
		if ( ! $group ) {
			throw new \InvalidArgumentException( 'Группа не найдена.' );
		}

		$isMember = false;
		foreach ( $this->records->findActiveByGroupId( $groupId ) as $rec ) {
			if ( $rec->studentPersonId === $studentPersonId ) {
				$isMember = true;
				break;
			}
		}
		if ( ! $isMember ) {
			throw new \InvalidArgumentException( 'Ученик не состоит в этой группе.' );
		}

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
			kind            : 'individual',
			status          : 'scheduled',
			studentPersonId : $studentPersonId,
			roomId          : $roomId,
		) );

		$this->dispatcher->dispatch(
			LogEvent::ScheduleChanged,
			new LearningEvent(
				event       : LogEvent::ScheduleChanged,
				actorUserId : $actorUserId,
				groupId     : $groupId,
				entityType  : 'group_lesson',
				entityId    : (string) $id,
				isPublic    : false,
			)
		);

		return $id;
	}

	/**
	 * Добавляет урок в программу группы вручную.
	 *
	 * Кросс-предметно: урок может принадлежать любому предмету (доп. занятие).
	 * Ручное добавление по умолчанию пиннуется — рукотворная дата не сдвигается reflow.
	 *
	 * @param string|null $label  Необязательный ярлык строки (напр. «Доп. Python #1»).
	 * @param bool        $pinned Зафиксировать строку (по умолчанию true для ручного добавления).
	 */
	public function addLesson( int $groupId, int $lessonId, int $actorUserId, ?string $label = null, bool $pinned = true ): int {
		$group  = $this->groups->findById( $groupId );
		$lesson = $this->lessonManager->get( $lessonId );

		if ( ! $group || ! $lesson ) {
			throw new \InvalidArgumentException( 'Группа или урок не найдены.' );
		}

		$position = $this->groupLessons->nextPosition( $groupId );
		$id       = $this->groupLessons->add( new GroupLessonInputDTO(
			groupId         : $groupId,
			lessonId        : $lessonId,
			position        : $position,
			isPinned        : $pinned,
			createdByUserId : $actorUserId,
			label           : $label,
		) );

		$this->dispatcher->dispatch(
			LogEvent::LessonAddedToProgram,
			new LearningEvent(
				event       : LogEvent::LessonAddedToProgram,
				actorUserId : $actorUserId,
				subjectKey  : $lesson->subjectKey,
				groupId     : $groupId,
				entityType  : 'lesson',
				entityId    : (string) $lessonId,
			)
		);

		return $id;
	}

	/**
	 * Дублирует строку программы: тот же урок ещё раз, новой строкой со своей датой.
	 * Кейс «провести один урок дважды на две даты». Дата сбрасывается — ставится заново.
	 *
	 * @return int ID новой строки или 0, если исходная не найдена.
	 */
	public function duplicateLesson( int $groupLessonId, int $actorUserId ): int {
		$row = $this->groupLessons->find( $groupLessonId );
		if ( ! $row ) {
			return 0;
		}

		$position = $this->groupLessons->nextPosition( $row->groupId );
		$newId    = $this->groupLessons->add( new GroupLessonInputDTO(
			groupId         : $row->groupId,
			lessonId        : $row->lessonId,
			position        : $position,
			extraWorkIds    : $row->extraWorkIds,
			isPinned        : true,
			teacherUserId   : $row->teacherUserId,
			createdByUserId : $actorUserId,
			label           : $row->label,
		) );

		$lesson = $row->lessonId ? $this->lessonManager->get( $row->lessonId ) : null;
		$this->dispatcher->dispatch(
			LogEvent::LessonAddedToProgram,
			new LearningEvent(
				event       : LogEvent::LessonAddedToProgram,
				actorUserId : $actorUserId,
				subjectKey  : $lesson?->subjectKey,
				groupId     : $row->groupId,
				entityType  : 'lesson',
				entityId    : (string) $row->lessonId,
			)
		);

		return $newId;
	}

	/**
	 * Продолжает тему на вторую дату (T12.6, D14): новая ПИННУТАЯ непристроенная
	 * строка (дата — заново, вручную через drag) со связью `continuedFromId` →
	 * исходная. В отличие от {@see self::duplicateLesson()} (независимая копия —
	 * отдельная тема) связь сохраняется: КТП считает обе строки ОДНОЙ темой
	 * (общий номер, части «1/2 · 2/2»), журнал получает второй столбец с меткой.
	 * Разрешено только для «родных» строк — цепочки из 3+ дат не поддерживаются.
	 *
	 * @return int ID новой строки или 0, если исходная не найдена / сама уже продолжение.
	 */
	public function continueLesson( int $groupLessonId, int $actorUserId ): int {
		$row = $this->groupLessons->find( $groupLessonId );
		if ( ! $row || null !== $row->continuedFromId ) {
			return 0;
		}

		$position = $this->groupLessons->nextPosition( $row->groupId );
		$newId    = $this->groupLessons->add( new GroupLessonInputDTO(
			groupId         : $row->groupId,
			lessonId        : $row->lessonId,
			position        : $position,
			extraWorkIds    : $row->extraWorkIds,
			isPinned        : true,
			teacherUserId   : $row->teacherUserId,
			createdByUserId : $actorUserId,
			label           : $row->label,
			continuedFromId : $row->id,
		) );

		$lesson = $row->lessonId ? $this->lessonManager->get( $row->lessonId ) : null;
		$this->dispatcher->dispatch(
			LogEvent::LessonAddedToProgram,
			new LearningEvent(
				event       : LogEvent::LessonAddedToProgram,
				actorUserId : $actorUserId,
				subjectKey  : $lesson?->subjectKey,
				groupId     : $row->groupId,
				entityType  : 'lesson',
				entityId    : (string) $row->lessonId,
			)
		);

		return $newId;
	}

	public function removeLesson( int $groupLessonId, int $actorUserId ): void {
		$row = $this->groupLessons->find( $groupLessonId );
		if ( ! $row ) {
			return;
		}
		$this->groupLessons->remove( $groupLessonId );

		$lesson = $this->lessonManager->get( $row->lessonId );
		$this->dispatcher->dispatch(
			LogEvent::LessonRemovedFromProgram,
			new LearningEvent(
				event       : LogEvent::LessonRemovedFromProgram,
				actorUserId : $actorUserId,
				groupId     : $row->groupId,
				subjectKey  : $lesson?->subjectKey,
				entityType  : 'lesson',
				entityId    : (string) $row->lessonId,
			)
		);
	}

	public function reorder( int $groupId, array $orderedIds, int $actorUserId ): void {
		$this->groupLessons->reorder( $groupId, $orderedIds );

		$this->dispatcher->dispatch(
			LogEvent::ScheduleChanged,
			new LearningEvent(
				event       : LogEvent::ScheduleChanged,
				actorUserId : $actorUserId,
				groupId     : $groupId,
				entityType  : 'group',
				entityId    : (string) $groupId,
				isPublic    : false,
			)
		);
	}

	public function schedule( int $groupLessonId, ?string $scheduledAt, ?int $teacherUserId, int $actorUserId ): void {
		$row = $this->groupLessons->find( $groupLessonId );
		if ( ! $row ) {
			throw new \InvalidArgumentException( 'Строка программы не найдена.' );
		}
		$this->groupLessons->updateSchedule( $groupLessonId, $scheduledAt, $teacherUserId );

		$this->dispatcher->dispatch(
			LogEvent::ScheduleChanged,
			new LearningEvent(
				event       : LogEvent::ScheduleChanged,
				actorUserId : $actorUserId,
				groupId     : $row->groupId,
				entityType  : 'group_lesson',
				entityId    : (string) $groupLessonId,
				isPublic    : false,
			)
		);
	}

	public function pin( int $groupLessonId, bool $pinned, int $actorUserId ): void {
		$row = $this->groupLessons->find( $groupLessonId );
		if ( ! $row ) {
			throw new \InvalidArgumentException( 'Строка программы не найдена.' );
		}
		$this->groupLessons->setPinned( $groupLessonId, $pinned );

		$this->dispatcher->dispatch(
			LogEvent::ScheduleChanged,
			new LearningEvent(
				event       : LogEvent::ScheduleChanged,
				actorUserId : $actorUserId,
				groupId     : $row->groupId,
				entityType  : 'group_lesson',
				entityId    : (string) $groupLessonId,
				isPublic    : false,
			)
		);
	}

	public function reflow( int $groupId, int $actorUserId ): int {
		$conflicts = $this->calendar->reflow( $groupId );

		$this->dispatcher->dispatch(
			LogEvent::ScheduleChanged,
			new LearningEvent(
				event       : LogEvent::ScheduleChanged,
				actorUserId : $actorUserId,
				groupId     : $groupId,
				entityType  : 'group',
				entityId    : (string) $groupId,
				isPublic    : false,
			)
		);

		return $conflicts;
	}

	/**
	 * Закрепляет тему на конкретную дату (drag-drop в КТП): дата + pin, затем
	 * остальные (непиннутые) темы переразливаются вокруг закреплённой.
	 *
	 * @param string $scheduledAt Дата/датавремя слота ('Y-m-d' или 'Y-m-d H:i:s').
	 */
	public function pinToDate( int $groupLessonId, string $scheduledAt, int $actorUserId ): void {
		$row = $this->groupLessons->find( $groupLessonId );
		if ( ! $row ) {
			throw new \InvalidArgumentException( 'Строка программы не найдена.' );
		}

		// Конфликт кабинета (T11.4): эффективный кабинет занятия ?? основной кабинет
		// группы; hard-block, если он занят ДРУГОЙ группой в это время. Занятия своей
		// группы (T12.5: две темы на один день) конфликтом не считаются — аналогично reflow.
		$group  = $this->groups->findById( $row->groupId );
		$roomId = ! empty( $row->roomId )
			? (int) $row->roomId
			: ( ( $group && ! empty( $group->room_id ) ) ? (int) $group->room_id : 0 );
		if ( $roomId > 0 ) {
			$end = ( $row->endsAt && '' !== $row->endsAt )
				? $row->endsAt
				: ( new \DateTimeImmutable( $scheduledAt ) )->modify( '+60 minutes' )->format( 'Y-m-d H:i:s' );
			if ( ! $this->roomAvailability->isFree( $roomId, $scheduledAt, $end, $groupLessonId, $row->groupId ) ) {
				throw new \InvalidArgumentException( 'Кабинет занят в это время другим занятием.' );
			}
		}

		$this->groupLessons->updateSchedule( $groupLessonId, $scheduledAt, $row->teacherUserId );
		$this->groupLessons->setPinned( $groupLessonId, true );
		$this->calendar->reflow( $row->groupId );

		$this->dispatcher->dispatch(
			LogEvent::ScheduleChanged,
			new LearningEvent(
				event       : LogEvent::ScheduleChanged,
				actorUserId : $actorUserId,
				groupId     : $row->groupId,
				entityType  : 'group_lesson',
				entityId    : (string) $groupLessonId,
				isPublic    : false,
			)
		);
	}

	/**
	 * Календарь КТП группы: метаданные периода (даты занятий, выходные) + темы
	 * программы с их размещением. Если курс группе не назначен — assigned=false.
	 *
	 * @return array{assigned:bool, period:?array, holidays:string[], lessonDays:string[], lessonTimes:array<string,string>, themes:array<int,array<string,mixed>>}
	 */
	public function getCalendar( int $groupId ): array {
		$group = $this->groups->findById( $groupId );
		$meta  = $this->calendar->periodMeta( $groupId );

		// Эффективный кабинет темы (T11.2): кабинет занятия ?? основной кабинет группы.
		$groupRoomId = ( $group && ! empty( $group->room_id ) ) ? (int) $group->room_id : 0;
		$roomNames   = array();
		foreach ( $this->rooms->findAll() as $r ) {
			$roomNames[ $r->id ] = $r->name;
		}

		$themes = array();
		foreach ( $this->numberThemes( $this->getProgram( $groupId ) ) as $entry ) {
			$row       = $entry['row'];
			$effRoomId = ! empty( $row->roomId ) ? (int) $row->roomId : $groupRoomId;
			$themes[]  = array(
				'group_lesson_id' => $row->id,
				'lesson_id'       => $row->lessonId,
				'n'               => $entry['n'],
				// T12.6 (D14): часть темы (1/2, 2/2) — origin+continuation считаются одной темой.
				'part'            => $entry['part'],
				'total_parts'     => $entry['totalParts'],
				'topic'           => $entry['topic'],
				'scheduled_at'    => $row->scheduledAt,
				'is_pinned'       => $row->isPinned,
				'room'            => ( $effRoomId && isset( $roomNames[ $effRoomId ] ) ) ? $roomNames[ $effRoomId ] : '',
			);
		}

		return array(
			'assigned'    => $group ? ! empty( $group->course_id ) : false,
			// Эпик 15: открытая группа — расписание не ведётся, фронт показывает
			// программу списком вместо КТП-доски (reflow/publish неприменимы).
			'open'        => $group && \Inc\Enums\Course\AccessMode::Open === \Inc\Enums\Course\AccessMode::fromValueOrDefault( (string) ( $group->access_mode ?? '' ) ),
			'period'      => $meta['period'],
			'holidays'    => $meta['holidays'],
			'lessonDays'  => $meta['lessonDays'],
			// T12.4: время занятия по дате ('16:00–17:30') для ячейки календаря КТП.
			'lessonTimes' => $meta['lessonTimes'],
			'themes'      => $themes,
			// T1.8: заблокирована ли КТП (опубликована) — фронт скрывает правки.
			'locked'      => $group ? ! empty( $group->program_locked_at ) : false,
			'locked_at'   => $group && ! empty( $group->program_locked_at ) ? (string) $group->program_locked_at : null,
		);
	}

	/**
	 * Опубликована ли (заблокирована) КТП группы (T1.8): после публикации
	 * структура и расписание программы недоступны для правок.
	 */
	public function isProgramLocked( int $groupId ): bool {
		$group = $this->groups->findById( $groupId );
		return (bool) ( $group && ! empty( $group->program_locked_at ) );
	}

	/** Дата публикации КТП или null. */
	public function programLockedAt( int $groupId ): ?string {
		$group = $this->groups->findById( $groupId );
		return $group && ! empty( $group->program_locked_at ) ? (string) $group->program_locked_at : null;
	}

	/** Публикует КТП: фиксирует дату блокировки и логирует (T1.8). */
	public function publishProgram( int $groupId, int $actorUserId ): void {
		$this->groups->setProgramLocked( $groupId, current_time( 'mysql' ) );
		$this->dispatchScheduleChanged( $groupId, $actorUserId );
	}

	/** Снимает публикацию КТП: возвращает возможность правок (T1.8). */
	public function unpublishProgram( int $groupId, int $actorUserId ): void {
		$this->groups->setProgramLocked( $groupId, null );
		$this->dispatchScheduleChanged( $groupId, $actorUserId );
	}

	private function dispatchScheduleChanged( int $groupId, int $actorUserId ): void {
		$this->dispatcher->dispatch(
			LogEvent::ScheduleChanged,
			new LearningEvent(
				event       : LogEvent::ScheduleChanged,
				actorUserId : $actorUserId,
				groupId     : $groupId,
				entityType  : 'group',
				entityId    : (string) $groupId,
				isPublic    : false,
			)
		);
	}

	public function getProgramRow( int $groupLessonId ): ?\Inc\DTO\Course\GroupLessonDTO {
		return $this->groupLessons->find( $groupLessonId );
	}

	/**
	 * НБ-9: индивидуальные занятия группы для режима КТП «Индивидуальные занятия».
	 * Каждый слот: ФИО ученика, дата/время, эффективный кабинет, привязанный урок
	 * (тема) либо пусто. Порядок — по дате.
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
			if ( 'individual' !== $row->kind || null === $row->studentPersonId ) {
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
	 * @throws \InvalidArgumentException если строка не найдена/не индивидуальная или урок не найден.
	 */
	public function assignLessonToIndividual( int $groupLessonId, int $lessonId, int $actorUserId ): void {
		$row = $this->groupLessons->find( $groupLessonId );
		if ( ! $row || 'individual' !== $row->kind ) {
			throw new \InvalidArgumentException( 'Индивидуальное занятие не найдено.' );
		}
		if ( null === $this->lessonManager->get( $lessonId ) ) {
			throw new \InvalidArgumentException( 'Урок не найден.' );
		}

		$this->groupLessons->setLessonId( $groupLessonId, $lessonId );
		$this->dispatchScheduleChanged( $row->groupId, $actorUserId );
	}

	/**
	 * Правка индивидуального занятия (B2): дата/время, кабинет, ученик, урок (тема).
	 * null-поля не меняются. Новый ученик должен состоять в группе занятия.
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
		$row = $this->groupLessons->find( $groupLessonId );
		if ( ! $row || 'individual' !== $row->kind ) {
			throw new \InvalidArgumentException( 'Индивидуальное занятие не найдено.' );
		}

		if ( null !== $scheduledAt && '' !== $scheduledAt ) {
			$this->groupLessons->updateSchedule( $groupLessonId, $scheduledAt, $row->teacherUserId, $endsAt );
		}
		if ( null !== $roomId ) {
			$this->groupLessons->setRoom( $groupLessonId, $roomId > 0 ? $roomId : null );
		}
		if ( null !== $studentPersonId && $studentPersonId > 0 ) {
			$isMember = false;
			foreach ( $this->records->findActiveByGroupId( $row->groupId ) as $rec ) {
				if ( $rec->studentPersonId === $studentPersonId ) {
					$isMember = true;
					break;
				}
			}
			if ( ! $isMember ) {
				throw new \InvalidArgumentException( 'Ученик не состоит в этой группе.' );
			}
			$this->groupLessons->setStudentPersonId( $groupLessonId, $studentPersonId );
		}
		if ( null !== $lessonId && $lessonId > 0 ) {
			if ( null === $this->lessonManager->get( $lessonId ) ) {
				throw new \InvalidArgumentException( 'Урок не найден.' );
			}
			$this->groupLessons->setLessonId( $groupLessonId, $lessonId );
		}

		$this->dispatchScheduleChanged( $row->groupId, $actorUserId );
	}

	/** @return array{row: \Inc\DTO\Course\GroupLessonDTO, topic: string, subject: string}[] */
	public function getProgram( int $groupId ): array {
		$rows   = $this->groupLessons->listByGroup( $groupId );
		$result = array();
		foreach ( $rows as $row ) {
			// Индивидуальные не входят в программу группы (D3).
			if ( 'individual' === $row->kind ) {
				continue;
			}
			$lesson   = $row->lessonId ? $this->lessonManager->get( $row->lessonId ) : null;
			$result[] = array(
				'row'     => $row,
				'topic'   => $lesson?->topic ?? '',
				'subject' => $lesson?->subjectKey ?? '',
			);
		}
		return $result;
	}

	/**
	 * Аннотирует темы номером/частью с учётом продолжений (T12.6, D14): пара
	 * origin+continuation получает ОБЩИЙ `n` и части «1/2 · 2/2» — КТП считает
	 * их одной темой. Порядок исходного списка (по `position`) сохраняется.
	 * Продолжение с отсутствующим (удалённым) оригиналом трактуется как
	 * самостоятельная тема (без падения) — цепочки из 3+ дат не поддерживаются.
	 *
	 * @param array<int,array{row: \Inc\DTO\Course\GroupLessonDTO, topic: string, subject: string}> $entries
	 * @return array<int,array{row: \Inc\DTO\Course\GroupLessonDTO, topic: string, subject: string, n:int, part:int, totalParts:int}>
	 */
	private function numberThemes( array $entries ): array {
		$existingIds = array();
		foreach ( $entries as $entry ) {
			$existingIds[ $entry['row']->id ] = true;
		}
		$continuationByOriginId = array();
		foreach ( $entries as $entry ) {
			$parentId = $entry['row']->continuedFromId;
			if ( null !== $parentId && isset( $existingIds[ $parentId ] ) ) {
				$continuationByOriginId[ $parentId ] = $entry;
			}
		}

		$numbered = array(); // row id => annotated entry
		$n        = 0;
		foreach ( $entries as $entry ) {
			$parentId = $entry['row']->continuedFromId;
			// Продолжение с существующим оригиналом — аннотируется вместе с ним ниже.
			if ( null !== $parentId && isset( $existingIds[ $parentId ] ) ) {
				continue;
			}
			++$n;
			$continuation = $continuationByOriginId[ $entry['row']->id ] ?? null;
			$total        = $continuation ? 2 : 1;

			$entry['n']               = $n;
			$entry['part']            = 1;
			$entry['totalParts']      = $total;
			$numbered[ $entry['row']->id ] = $entry;

			if ( $continuation ) {
				$continuation['n']          = $n;
				$continuation['part']       = 2;
				$continuation['totalParts'] = $total;
				$numbered[ $continuation['row']->id ] = $continuation;
			}
		}

		$ordered = array();
		foreach ( $entries as $entry ) {
			$ordered[] = $numbered[ $entry['row']->id ];
		}
		return $ordered;
	}
}
