<?php

declare( strict_types=1 );

namespace Inc\Services\Group;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Course\GroupLessonInputDTO;
use Inc\DTO\Log\Events\LearningEvent;
use Inc\Enums\Log\LogEvent;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;

class ScheduleService {

	public function __construct(
		private readonly GroupLessonRepository       $groupLessons,
		private readonly LessonManager               $lessonManager,
		private readonly GroupsRepository            $groups,
		private readonly LogEventDispatcherInterface $dispatcher,
		private readonly SessionCalendarService      $calendar,
		private readonly StudentRecordRepository     $records,
	) {}

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
		int     $actorUserId
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

	public function reflow( int $groupId, int $actorUserId ): void {
		$this->calendar->reflow( $groupId );

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
	 * @return array{assigned:bool, period:?array, holidays:string[], lessonDays:string[], themes:array<int,array<string,mixed>>}
	 */
	public function getCalendar( int $groupId ): array {
		$group = $this->groups->findById( $groupId );
		$meta  = $this->calendar->periodMeta( $groupId );

		$themes = array();
		foreach ( $this->getProgram( $groupId ) as $i => $entry ) {
			$row      = $entry['row'];
			$themes[] = array(
				'group_lesson_id' => $row->id,
				'lesson_id'       => $row->lessonId,
				'n'               => $i + 1,
				'topic'           => $entry['topic'],
				'scheduled_at'    => $row->scheduledAt,
				'is_pinned'       => $row->isPinned,
			);
		}

		return array(
			'assigned'   => $group ? ! empty( $group->course_id ) : false,
			'period'     => $meta['period'],
			'holidays'   => $meta['holidays'],
			'lessonDays' => $meta['lessonDays'],
			'themes'     => $themes,
		);
	}

	public function getProgramRow( int $groupLessonId ): ?\Inc\DTO\Course\GroupLessonDTO {
		return $this->groupLessons->find( $groupLessonId );
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
}
