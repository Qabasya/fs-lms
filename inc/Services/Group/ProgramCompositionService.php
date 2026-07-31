<?php

declare( strict_types=1 );

namespace Inc\Services\Group;

use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\GroupLessonInputDTO;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;

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
	 */
	public function __construct(
		private GroupLessonRepository  $groupLessons,
		private LessonManager          $lessonManager,
		private GroupsRepository       $groups,
		private ScheduleEventPublisher $events,
	) {}

	/**
	 * Добавляет урок в программу группы вручную.
	 *
	 * Кросс-предметно: урок может принадлежать любому предмету (доп. занятие).
	 * Ручное добавление по умолчанию пиннуется — рукотворная дата не сдвигается reflow.
	 *
	 * @param int         $groupId     ID группы
	 * @param int         $lessonId    ID урока банка
	 * @param int         $actorUserId Автор изменения
	 * @param string|null $label       Необязательный ярлык строки (напр. «Доп. Python #1»)
	 * @param bool        $pinned      Зафиксировать строку (по умолчанию true для ручного добавления)
	 *
	 * @return int ID созданной строки
	 *
	 * @throws \InvalidArgumentException Если группа или урок не найдены
	 */
	public function addLesson( int $groupId, int $lessonId, int $actorUserId, ?string $label = null, bool $pinned = true ): int {
		$group  = $this->groups->findById( $groupId );
		$lesson = $this->lessonManager->get( $lessonId );

		if ( ! $group || ! $lesson ) {
			throw new \InvalidArgumentException( 'Группа или урок не найдены.' );
		}

		$id = $this->groupLessons->add( new GroupLessonInputDTO(
			groupId         : $groupId,
			lessonId        : $lessonId,
			position        : $this->groupLessons->nextPosition( $groupId ),
			isPinned        : $pinned,
			createdByUserId : $actorUserId,
			label           : $label,
		) );

		$this->events->lessonAdded( $groupId, $lessonId, $lesson->subjectKey, $actorUserId );

		return $id;
	}

	/**
	 * Дублирует строку программы: тот же урок ещё раз, новой строкой со своей датой.
	 * Кейс «провести один урок дважды на две даты». Дата сбрасывается — ставится заново.
	 *
	 * @param int $groupLessonId ID исходной строки
	 * @param int $actorUserId   Автор изменения
	 *
	 * @return int ID новой строки или 0, если исходная не найдена
	 */
	public function duplicateLesson( int $groupLessonId, int $actorUserId ): int {
		$row = $this->groupLessons->find( $groupLessonId );
		if ( ! $row ) {
			return 0;
		}

		$newId = $this->groupLessons->add( new GroupLessonInputDTO(
			groupId         : $row->groupId,
			lessonId        : $row->lessonId,
			position        : $this->groupLessons->nextPosition( $row->groupId ),
			extraWorkIds    : $row->extraWorkIds,
			isPinned        : true,
			teacherUserId   : $row->teacherUserId,
			createdByUserId : $actorUserId,
			label           : $row->label,
		) );

		$this->events->lessonAdded( $row->groupId, $row->lessonId, $this->subjectOf( $row->lessonId ), $actorUserId );

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
	 * @param int $groupLessonId ID исходной строки
	 * @param int $actorUserId   Автор изменения
	 *
	 * @return int ID новой строки или 0, если исходная не найдена / сама уже продолжение
	 */
	public function continueLesson( int $groupLessonId, int $actorUserId ): int {
		$row = $this->groupLessons->find( $groupLessonId );
		if ( ! $row || null !== $row->continuedFromId ) {
			return 0;
		}

		$newId = $this->groupLessons->add( new GroupLessonInputDTO(
			groupId         : $row->groupId,
			lessonId        : $row->lessonId,
			position        : $this->groupLessons->nextPosition( $row->groupId ),
			extraWorkIds    : $row->extraWorkIds,
			isPinned        : true,
			teacherUserId   : $row->teacherUserId,
			createdByUserId : $actorUserId,
			label           : $row->label,
			continuedFromId : $row->id,
		) );

		$this->events->lessonAdded( $row->groupId, $row->lessonId, $this->subjectOf( $row->lessonId ), $actorUserId );

		return $newId;
	}

	/**
	 * Убирает строку из программы группы.
	 *
	 * @param int $groupLessonId ID строки программы
	 * @param int $actorUserId   Автор изменения
	 *
	 * @return void
	 */
	public function removeLesson( int $groupLessonId, int $actorUserId ): void {
		$row = $this->groupLessons->find( $groupLessonId );
		if ( ! $row ) {
			return;
		}

		$this->groupLessons->remove( $groupLessonId );

		$lesson = $this->lessonManager->get( $row->lessonId );
		$this->events->lessonRemoved( $row->groupId, $row->lessonId, $lesson?->subjectKey, $actorUserId );
	}

	/**
	 * Переупорядочивает строки программы.
	 *
	 * @param int   $groupId     ID группы
	 * @param int[] $orderedIds  ID строк в новом порядке
	 * @param int   $actorUserId Автор изменения
	 *
	 * @return void
	 */
	public function reorder( int $groupId, array $orderedIds, int $actorUserId ): void {
		$this->groupLessons->reorder( $groupId, $orderedIds );
		$this->events->groupChanged( $groupId, $actorUserId );
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
	 * @param array<int,array{row: GroupLessonDTO, topic: string, subject: string}> $entries Строки программы
	 *
	 * @return array<int,array{row: GroupLessonDTO, topic: string, subject: string, n:int, part:int, totalParts:int}>
	 */
	public function numberThemes( array $entries ): array {
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

			$entry['n']                    = $n;
			$entry['part']                 = 1;
			$entry['totalParts']           = $total;
			$numbered[ $entry['row']->id ] = $entry;

			if ( $continuation ) {
				$continuation['n']                    = $n;
				$continuation['part']                 = 2;
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
