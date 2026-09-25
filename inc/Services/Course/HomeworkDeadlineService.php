<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\WorkDTO;
use Inc\Enums\Course\LessonStatus;
use Inc\Enums\Course\LessonVisibility;
use Inc\Enums\Course\WorkType;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;

/**
 * Class HomeworkDeadlineService
 *
 * Эффективный срок работы занятия и несданные ДЗ. Срок — явный дедлайн работы
 * ({@see GroupLessonDTO::deadlineForWork()}), а у домашнего задания без него —
 * начало следующего занятия ученика: ДЗ задают «к следующему уроку».
 *
 * Одна логика на три экрана: «Сводка по ученику», дедлайны и оценки в кабинете
 * ученика (его же видит родитель) — иначе ДЗ было бы несданным в одном месте
 * и бессрочным в другом.
 *
 * @package Inc\Services\Course
 */
class HomeworkDeadlineService {

	public function __construct(
		private readonly EffectiveWorksResolver  $worksResolver,
		private readonly LessonVisibilityService $visibility,
		private readonly GroupLessonRepository   $groupLessons,
	) {}

	/**
	 * Начало следующего занятия для каждой строки — в пределах её группы. Следующим
	 * считается групповое занятие, не отменённое и не перенесённое: те же правила,
	 * что у уведомлений о сдаче ДЗ ({@see \Inc\Services\Profile\NotificationCronService}),
	 * иначе «Не сдано» и «Дедлайн пропущен» расходились бы по времени.
	 *
	 * @param GroupLessonDTO[] $rows Занятия, видимые ученику (чужие индивидуальные уже отброшены)
	 *
	 * @return array<int, string> group_lesson_id → начало следующего занятия (нет следующего — ключа нет)
	 */
	public function nextLessonStarts( array $rows ): array {
		$starts = array();
		foreach ( $rows as $row ) {
			if ( $row->scheduledAt && ! $row->kind->isIndividual() && ! $this->freesSlot( $row ) ) {
				$starts[ $row->groupId ][] = (string) $row->scheduledAt;
			}
		}
		foreach ( $starts as &$groupStarts ) {
			sort( $groupStarts );
		}
		unset( $groupStarts );

		$next = array();
		foreach ( $rows as $row ) {
			if ( ! $row->scheduledAt ) {
				continue;
			}
			foreach ( $starts[ $row->groupId ] ?? array() as $start ) {
				if ( $start > $row->scheduledAt ) {
					$next[ $row->id ] = $start;
					break;
				}
			}
		}

		return $next;
	}

	/**
	 * Срок работы: явный дедлайн, у ДЗ без него — начало следующего занятия.
	 *
	 * @param string|null $nextStart Из {@see self::nextLessonStarts()}
	 */
	public function deadlineFor( GroupLessonDTO $row, WorkDTO $work, ?string $nextStart ): ?string {
		$explicit = $row->deadlineForWork( $work->id );
		if ( null !== $explicit ) {
			return $explicit;
		}

		return WorkType::Homework === $work->workType ? $nextStart : null;
	}

	/**
	 * Срок работы, когда под рукой нет списка занятий (сдача работы): следующее
	 * занятие ищется среди занятий группы.
	 */
	public function deadlineForRow( GroupLessonDTO $row, WorkDTO $work ): ?string {
		$explicit = $row->deadlineForWork( $work->id );
		if ( null !== $explicit || WorkType::Homework !== $work->workType ) {
			return $explicit;
		}

		return $this->nextLessonStarts( array( $row, ...$this->groupLessons->listByGroup( $row->groupId ) ) )[ $row->id ] ?? null;
	}

	/**
	 * ДЗ, которые ученик не сдал к сроку. Дата зачисления не учитывается:
	 * пришедший в середине курса получает прошлые ДЗ несданными, как все.
	 *
	 * @param GroupLessonDTO[]                 $rows      Занятия, видимые ученику
	 * @param array<int, array<int, true>>     $submitted group_lesson_id → [work_id => true] работ со сдачей
	 * @param string                           $now       Текущее время (mysql)
	 *
	 * @return array<int, array<int, array{work: WorkDTO, due_at: string}>> group_lesson_id → несданные ДЗ
	 */
	public function missed( array $rows, array $submitted, string $now ): array {
		$next = $this->nextLessonStarts( $rows );
		$out  = array();

		foreach ( $rows as $row ) {
			// Скрытое занятие ученику не выдавалось, отменённое/перенесённое — не состоялось.
			// Видимость — эффективная: `hidden` в БД открывается сам в момент занятия.
			if ( LessonVisibility::Hidden->value === $this->visibility->effectiveVisibility( $row ) || $this->freesSlot( $row ) ) {
				continue;
			}

			foreach ( $this->worksResolver->resolve( $row ) as $work ) {
				if ( WorkType::Homework !== $work->workType || isset( $submitted[ $row->id ][ $work->id ] ) ) {
					continue;
				}
				$dueAt = $this->deadlineFor( $row, $work, $next[ $row->id ] ?? null );
				if ( null === $dueAt || $dueAt > $now ) {
					continue;
				}
				$out[ $row->id ][] = array( 'work' => $work, 'due_at' => $dueAt );
			}
		}

		return $out;
	}

	/**
	 * Все ДЗ занятий, срок которых уже наступил, по сроку от ранних к поздним —
	 * серия несданных считается по ним ({@see \Inc\Services\Profile\AdminAlertService}).
	 * Те же правила, что у {@see self::missed()}: скрытые, отменённые и
	 * перенесённые занятия не в счёт.
	 *
	 * @param GroupLessonDTO[] $rows Занятия группы
	 *
	 * @return array<int, array{row: GroupLessonDTO, work: WorkDTO, due_at: string}>
	 */
	public function dueHomework( array $rows, string $now ): array {
		$next = $this->nextLessonStarts( $rows );
		$out  = array();

		foreach ( $rows as $row ) {
			if ( LessonVisibility::Hidden->value === $this->visibility->effectiveVisibility( $row ) || $this->freesSlot( $row ) ) {
				continue;
			}
			foreach ( $this->worksResolver->resolve( $row ) as $work ) {
				if ( WorkType::Homework !== $work->workType ) {
					continue;
				}
				$dueAt = $this->deadlineFor( $row, $work, $next[ $row->id ] ?? null );
				if ( null !== $dueAt && $dueAt <= $now ) {
					$out[] = array( 'row' => $row, 'work' => $work, 'due_at' => $dueAt );
				}
			}
		}

		usort( $out, static fn( array $a, array $b ): int => strcmp( $a['due_at'], $b['due_at'] ) );

		return $out;
	}

	private function freesSlot( GroupLessonDTO $row ): bool {
		return LessonStatus::fromValueOrDefault( $row->status )->freesSlot();
	}
}
