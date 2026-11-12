<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Contracts\ClockInterface;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Log\Events\LearningEvent;
use Inc\DTO\Course\LessonDTO;
use Inc\Enums\Course\LessonVisibility;
use Inc\Enums\Course\RankingAssignmentOrigin;
use Inc\Enums\Log\LogEvent;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;

class LessonVisibilityService {

	public function __construct(
		private readonly GroupLessonRepository       $groupLessons,
		private readonly LessonManager               $lessonManager,
		private readonly LogEventDispatcherInterface $dispatcher,
		private readonly ClockInterface              $clock,
		private readonly ?RankingAssignmentSnapshotService $rankingSnapshots = null,
		private readonly bool $captureRankingAssignments = true,
		private readonly ?RankingLessonPublicationService $rankingPublication = null,
		private readonly ?RankingScheduleHistoryService $rankingHistory = null,
	) {}


	/**
	 * Возвращает эффективную видимость строки программы.
	 *
	 * Если урок хранит visibility='hidden', но `scheduled_at` уже наступил —
	 * возвращает 'open' (lazy auto-open по времени занятия, T2.34).
	 * Ручное переключение visibility='archived' или явное 'open' не перебивается.
	 *
	 * Урок курса в статусе черновика сам не открывается: курс дописывается по ходу
	 * года, и заготовка, до которой не дошли руки к дате занятия, не должна уйти
	 * ученикам пустой. Откроется, как только урок опубликуют.
	 *
	 * @param \Inc\DTO\Course\GroupLessonDTO $row
	 * @return string
	 */
	public function effectiveVisibility( \Inc\DTO\Course\GroupLessonDTO $row ): string {
		if (
			LessonVisibility::Hidden->value === $row->visibility
			&& null !== $row->scheduledAt
			&& $this->clock->now() >= $row->scheduledAt
			&& ( ! $row->lessonId || $this->lessonManager->isPublished( $row->lessonId ) )
		) {
			return LessonVisibility::Open->value;
		}
		return $row->visibility;
	}

	/**
	 * Домешивает новые work-шаги эталонного урока в уже открытые оккурренсы (Course Builder).
	 *
	 * copy-on-publish замораживает набор работ строго на момент открытия — если после
	 * этого в урок добавили работу, уже открытые для групп занятия её не увидят
	 * (`work_ids_snapshot` не меняется). Идемпотентно докладывает недостающие id
	 * в `extra_work_ids` (снапшот не трогаем — уже сданное/оценённое не задеваем).
	 */
	public function syncExtraWorksForOpenOccurrences( int $lessonId, bool $confirm = true ): void {
		$lesson = $this->lessonManager->get( $lessonId );
		if ( ! $lesson ) {
			return;
		}
		$liveWorkIds = $lesson->workIds();
		$capture = $this->captureRankingAssignments && null !== $this->rankingSnapshots;
		$rows = $capture
			? $this->groupLessons->listByLessonId( $lessonId, true )
			: $this->groupLessons->listByLessonId( $lessonId );
		foreach ( $rows as $row ) {
			if ( ! $row->isPublished() && ! $capture ) {
				continue;
			}
			if ( $capture ) {
				$this->rankingSnapshots->synchronized( function () use ( $row, $liveWorkIds, $confirm ): void {
					$current = $this->groupLessons->find( $row->id );
					if ( ! $current ) {
						return;
					}
					$effective = array_merge( $current->workIdsSnapshot ?? array(), $current->extraWorkIds );
					$missing = array_values( array_diff( $liveWorkIds, $effective ) );
					if ( ! $missing ) {
						return;
					}
					if ( LessonVisibility::Hidden->value !== $this->effectiveVisibility( $current ) ) {
						if ( $confirm ) {
							$this->rankingSnapshots->capture( $current->id, $missing, null, RankingAssignmentOrigin::Lesson );
						} else {
							$this->rankingSnapshots->markPublicationUnconfirmed( $current->id, $missing, RankingAssignmentOrigin::Lesson );
						}
					} else {
						$this->rankingSnapshots->markPending( $current->id, $missing, RankingAssignmentOrigin::Lesson );
					}
					$merged = array_values( array_unique( array_merge( $current->extraWorkIds, $missing ) ) );
					if ( ! $this->groupLessons->setExtraWorkIds( $current->id, $merged ) ) {
						throw new \RuntimeException( 'Не удалось сохранить дополнительные работы.' );
					}
				} );
				continue;
			}
			$effective = array_merge( $row->workIdsSnapshot ?? array(), $row->extraWorkIds );
			$missing   = array_diff( $liveWorkIds, $effective );
			if ( empty( $missing ) ) {
				continue;
			}
			$merged = array_values( array_unique( array_merge( $row->extraWorkIds, $missing ) ) );
			$this->groupLessons->setExtraWorkIds( $row->id, $merged );
		}
	}

	public function capturesRankingAssignments(): bool {
		return $this->captureRankingAssignments && null !== $this->rankingSnapshots && null !== $this->rankingPublication && null !== $this->rankingHistory;
	}

	/** Фиксирует неопределённость до WP-записи и подтверждает выдачу после неё. */
	public function saveStepsWithHistory( int $lessonId, LessonDTO $lesson ): bool {
		if ( ! $this->capturesRankingAssignments() ) {
			return false;
		}
		$result = false;
		$started = $this->clock->now();
		$this->rankingSnapshots->synchronizedWrite(
			function () use ( $lessonId, $lesson ): void {
				wp_cache_delete( $lessonId, 'post_meta' );
				$previous = $this->lessonManager->get( $lessonId );
				if ( ! $previous ) {
					throw new \InvalidArgumentException( 'Урок не найден.' );
				}
				foreach ( $this->groupLessons->listByLessonId( $lessonId, true ) as $row ) {
					if ( LessonVisibility::Hidden->value === $this->effectiveVisibility( $row ) ) {
						continue;
					}
					$this->rankingHistory->captureBeforeMutation( $row );
					$existing = array_merge( $previous->workIds(), $row->workIdsSnapshot ?? array(), $row->extraWorkIds );
					$added = array_values( array_diff( $lesson->workIds(), $existing ) );
					$this->rankingSnapshots->markPublicationUnconfirmed( $row->id, $added, RankingAssignmentOrigin::Lesson );
				}
			},
			function () use ( $lessonId, $lesson, $started, &$result ): void {
				if ( ! $this->lessonManager->update( $lessonId, $lesson ) ) {
					return;
				}
				wp_cache_delete( $lessonId, 'post_meta' );
				$saved = $this->lessonManager->get( $lessonId );
				if ( ! $saved || $saved->workIds() !== $lesson->workIds() ) {
					return;
				}
				$confirmed = $this->rankingPublication->samePeriod( $started, $this->clock->now() );
				$this->syncExtraWorksForOpenOccurrences( $lessonId, $confirmed );
				$result = true;
			}
		);
		return $result;
	}

	/** Явно подтянуть новую версию work_ids из эталонного урока (перезаписывает снапшот). */
	public function refreshFromLesson( int $groupLessonId, int $actorUserId ): void {
		$row    = $this->groupLessons->find( $groupLessonId );
		$lesson = ( $row && $row->lessonId ) ? $this->lessonManager->get( $row->lessonId ) : null;
		if ( ! $row || ! $lesson ) {
			throw new \InvalidArgumentException( 'Строка программы или урок не найдены.' );
		}
		if ( $this->captureRankingAssignments && $this->rankingSnapshots ) {
			$this->rankingSnapshots->synchronized( function () use ( $groupLessonId ): void {
				$current = $this->groupLessons->find( $groupLessonId );
				$lesson = $current && $current->lessonId ? $this->lessonManager->get( $current->lessonId ) : null;
				if ( ! $current || ! $lesson ) {
					throw new \InvalidArgumentException( 'Строка программы или урок не найдены.' );
				}
				$existing = array_merge( $current->workIdsSnapshot ?? array(), $current->extraWorkIds );
				$added = array_values( array_diff( $lesson->workIds(), $existing ) );
				if ( LessonVisibility::Hidden->value === $this->effectiveVisibility( $current ) ) {
					$this->rankingSnapshots->markPending( $groupLessonId, $added, RankingAssignmentOrigin::Lesson );
				} else {
					// Live-шаги могли стать доступны до этого обновления снапшота.
					$this->rankingSnapshots->markPublicationUnconfirmed( $groupLessonId, $added, RankingAssignmentOrigin::Lesson );
				}
				if ( ! $this->groupLessons->setWorkIdsSnapshot( $groupLessonId, $lesson->workIds() ) ) {
					throw new \RuntimeException( 'Не удалось обновить работы занятия.' );
				}
			} );
		} else {
			$this->groupLessons->setWorkIdsSnapshot( $groupLessonId, $lesson->workIds() );
		}

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
}
