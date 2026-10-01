<?php

declare( strict_types=1 );

namespace Inc\Services\Group;

use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\GroupLessonInputDTO;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Services\Course\GroupLessonUsageGuard;

/**
 * Class ProgramCompositionService
 *
 * Состав КТП группы: какие темы в программе, в каком порядке и опубликована ли она.
 *
 * @package Inc\Services\Group
 *
 * ### Границы ответственности
 *
 * - **Здесь** — добавление/дублирование/продолжение/удаление тем, порядок,
 *   нумерация тем с учётом продолжений и публикация (lock) КТП.
 * - Даты и раскладка — {@see ScheduleReflowService}.
 * - Индивидуальные занятия — {@see IndividualLessonService} (в программу группы
 *   они не входят, D3).
 * - Представление КТП для фронта — {@see GroupCalendarService}.
 */
readonly class ProgramCompositionService {

	/**
	 * @param GroupLessonRepository  $groupLessons Строки программы
	 * @param LessonManager          $lessonManager Банк уроков
	 * @param GroupsRepository       $groups       Группы
	 * @param ScheduleEventPublisher $events       Публикация событий обучения
	 * @param ScheduleReflowService  $schedule     Даты: постановка вставленной темы
	 * @param GroupLessonUsageGuard  $usage        Есть ли по занятию прогресс/сдачи (можно ли убрать строку)
	 */
	public function __construct(
		private GroupLessonRepository  $groupLessons,
		private LessonManager          $lessonManager,
		private GroupsRepository       $groups,
		private ScheduleEventPublisher $events,
		private ScheduleReflowService  $schedule,
		private GroupLessonUsageGuard  $usage,
	) {}

	/**
	 * Продолжает тему на вторую дату (T12.6, D14): новая строка со связью
	 * `continuedFromId` → исходная. Связь сохраняется: КТП считает обе строки ОДНОЙ
	 * темой (общий номер, части «1/2 · 2/2»), журнал получает второй столбец с меткой.
	 * Разрешено только для «родных» строк без продолжения — цепочки из 3+ дат не
	 * поддерживаются, а повторный клик не должен плодить вторую копию.
	 * Лишнее продолжение убирается через {@see removeContinuation()}.
	 *
	 * Продолжение встаёт в программу сразу за исходной строкой, а не в конец: если
	 * исходная тема уже на дате, вторая часть занимает следующее занятие, а
	 * непроведённый хвост сдвигается на одно окно ({@see ScheduleReflowService::placeInserted()}).
	 * Исходная без даты — продолжение ждёт в пуле вместе с ней.
	 *
	 * @param int $groupLessonId ID исходной строки
	 * @param int $actorUserId   Автор изменения
	 *
	 * @return int ID новой строки или 0, если исходная не найдена / сама продолжение / уже продолжена
	 */
	public function continueLesson( int $groupLessonId, int $actorUserId ): int {
		$row = $this->groupLessons->find( $groupLessonId );
		if ( ! $row || null !== $row->continuedFromId || $this->hasContinuation( $row ) ) {
			return 0;
		}

		$position = $row->position + 1;
		$this->groupLessons->shiftPositions( $row->groupId, $position );

		$newId = $this->groupLessons->add( new GroupLessonInputDTO(
			groupId         : $row->groupId,
			lessonId        : $row->lessonId,
			position        : $position,
			extraWorkIds    : $row->extraWorkIds,
			teacherUserId   : $row->teacherUserId,
			createdByUserId : $actorUserId,
			label           : $row->label,
			continuedFromId : $row->id,
		) );

		$this->events->lessonAdded( $row->groupId, $row->lessonId, $this->subjectOf( $row->lessonId ), $actorUserId );

		if ( null !== $row->scheduledAt ) {
			$this->schedule->placeInserted( $newId, $actorUserId );
		}

		return $newId;
	}

	/**
	 * Убирает продолжение темы (обратная операция к {@see continueLesson()}): строка
	 * исчезает из программы, окно, которое она занимала, освобождается, а занятия
	 * после него подтягиваются на одно окно назад — так же, как при возврате темы в пул.
	 *
	 * Нельзя, если занятие уже состоялось (проведено или отмечена посещаемость) или по нему
	 * есть прогресс учеников, сдачи, попытки заданий: удаление потеряло бы данные.
	 *
	 * @param int $groupLessonId ID строки-продолжения
	 * @param int $actorUserId   Автор изменения
	 *
	 * @throws \InvalidArgumentException Если строка не найдена, не является продолжением или её нельзя убрать
	 */
	public function removeContinuation( int $groupLessonId, int $actorUserId ): void {
		$row = $this->groupLessons->find( $groupLessonId );
		if ( ! $row ) {
			throw new \InvalidArgumentException( 'Занятие не найдено.' );
		}

		if ( null === $row->continuedFromId ) {
			throw new \InvalidArgumentException( 'Это не продолжение темы — убрать можно только вторую дату.' );
		}

		if ( $row->isFact() ) {
			throw new \InvalidArgumentException( 'Занятие уже состоялось (проведено или отмечена посещаемость) — убрать его нельзя, это факт журнала.' );
		}

		if ( ! $this->usage->isSafeToRemove( $groupLessonId ) ) {
			throw new \InvalidArgumentException( 'По этому занятию уже есть прогресс или сдачи учеников — убрать его нельзя.' );
		}

		// Дата освобождается, хвост подтягивается (нет даты — строка просто ждала в пуле).
		$this->schedule->returnToPool( $groupLessonId, $actorUserId );
		$this->groupLessons->remove( $groupLessonId );

		$this->events->lessonRemoved( $row->groupId, $row->lessonId, $this->subjectOf( $row->lessonId ), $actorUserId );
	}

	/** Есть ли у строки продолжение в программе группы. */
	private function hasContinuation( GroupLessonDTO $row ): bool {
		foreach ( $this->groupLessons->listByGroup( $row->groupId ) as $other ) {
			if ( $other->continuedFromId === $row->id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Строка программы по ID.
	 *
	 * @param int $groupLessonId ID строки
	 */
	public function getProgramRow( int $groupLessonId ): ?GroupLessonDTO {
		return $this->groupLessons->find( $groupLessonId );
	}

	/**
	 * Программа группы: строки + тема и предмет привязанного урока.
	 * Индивидуальные занятия в программу не входят (D3).
	 *
	 * @param int $groupId ID группы
	 *
	 * @return array{row: GroupLessonDTO, topic: string, subject: string}[]
	 */
	public function getProgram( int $groupId ): array {
		$result = array();

		foreach ( $this->groupLessons->listByGroup( $groupId ) as $row ) {
			if ( $row->kind->isIndividual() ) {
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
	 * самостоятельная тема (без падения). Если у темы по ошибке несколько продолжений
	 * (старые данные до защиты от повторов), части нумеруются подряд: «1/3 · 2/3 · 3/3»,
	 * и каждое можно убрать — ни одна строка не пропадает из программы.
	 *
	 * @param array<int,array{row: GroupLessonDTO, topic: string, subject: string}> $entries Строки программы
	 *
	 * @return array<int,array{row: GroupLessonDTO, topic: string, subject: string, n:int, part:int, totalParts:int}>
	 */
	public function numberThemes( array $entries ): array {
		$existingIds = array();
		foreach ( $entries as $entry ) {
			$existingIds[ $entry['row']->id ] = true;
		}

		$continuationsByOriginId = array();
		foreach ( $entries as $entry ) {
			$parentId = $entry['row']->continuedFromId;
			if ( null !== $parentId && isset( $existingIds[ $parentId ] ) ) {
				$continuationsByOriginId[ $parentId ][] = $entry;
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
			$continuations = $continuationsByOriginId[ $entry['row']->id ] ?? array();
			$total         = 1 + count( $continuations );

			$entry['n']                    = $n;
			$entry['part']                 = 1;
			$entry['totalParts']           = $total;
			$numbered[ $entry['row']->id ] = $entry;

			foreach ( $continuations as $i => $continuation ) {
				$continuation['n']                    = $n;
				$continuation['part']                 = $i + 2;
				$continuation['totalParts']           = $total;
				$numbered[ $continuation['row']->id ] = $continuation;
			}
		}

		$ordered = array();
		foreach ( $entries as $entry ) {
			$ordered[] = $numbered[ $entry['row']->id ];
		}

		return $ordered;
	}

	/**
	 * Опубликована ли (заблокирована) КТП группы (T1.8): после публикации
	 * структура и расписание программы недоступны для правок.
	 *
	 * @param int $groupId ID группы
	 */
	public function isProgramLocked( int $groupId ): bool {
		$group = $this->groups->findById( $groupId );

		return (bool) ( $group && ! empty( $group->program_locked_at ) );
	}

	/**
	 * Дата публикации КТП или null.
	 *
	 * @param int $groupId ID группы
	 */
	public function programLockedAt( int $groupId ): ?string {
		$group = $this->groups->findById( $groupId );

		return $group && ! empty( $group->program_locked_at ) ? (string) $group->program_locked_at : null;
	}

	/**
	 * Публикует КТП: фиксирует дату блокировки и логирует (T1.8).
	 *
	 * @param int $groupId     ID группы
	 * @param int $actorUserId Автор изменения
	 *
	 * @return void
	 */
	public function publishProgram( int $groupId, int $actorUserId ): void {
		$this->groups->setProgramLocked( $groupId, current_time( 'mysql' ) );
		$this->events->groupChanged( $groupId, $actorUserId );
	}

	/**
	 * Снимает публикацию КТП: возвращает возможность правок (T1.8).
	 *
	 * @param int $groupId     ID группы
	 * @param int $actorUserId Автор изменения
	 *
	 * @return void
	 */
	public function unpublishProgram( int $groupId, int $actorUserId ): void {
		$this->groups->setProgramLocked( $groupId, null );
		$this->events->groupChanged( $groupId, $actorUserId );
	}

	/**
	 * Предмет урока банка (для события) — null, если урок не привязан/удалён.
	 *
	 * @param int|null $lessonId ID урока
	 */
	private function subjectOf( ?int $lessonId ): ?string {
		return $lessonId ? $this->lessonManager->get( $lessonId )?->subjectKey : null;
	}
}
