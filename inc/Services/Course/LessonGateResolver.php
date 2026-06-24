<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\Enums\Course\GateState;
use Inc\Managers\Course\LessonManager;
use Inc\Services\Assessment\ExamLockService;

/**
 * Class LessonGateResolver
 *
 * Гейтинг доступа (★, T1.5.10) — слой ВЫПОЛНЕНИЯ поверх доступа по членству.
 * Урок: доступ (`LessonAccessPolicy::canRead` — членство/видимость) + дата (`group_lessons.scheduled_at`).
 * Шаг: гейт-конфиг `payload['gate']` = `none` | `sequential` (предыдущий шаг пройден) |
 * `after:<step_key>` (указанный шаг пройден). Выполнение читается из `LessonProgressService`
 * (который для work/assessment резолвит fact-таблицы). Возвращает `locked`/`available`.
 *
 * Прим.: lesson-`unlock` варианты `after_prev_lesson`/`after_work` требуют хранимого конфига +
 * контекста программы — добавятся, когда конфиг будет авторингом (вне MVP-резолвера).
 *
 * @package Inc\Services\Course
 */
class LessonGateResolver {

	public function __construct(
		private readonly LessonProgressService $progress,
		private readonly LessonManager         $lessons,
		private readonly LessonAccessPolicy    $accessPolicy,
		private readonly ExamLockService       $examLock,
		private readonly ClockInterface        $clock,
	) {}

	/**
	 * Доступен ли урок программы ученику: членство/видимость (`canRead`) + дата занятия.
	 * Верхний приоритет: активная попытка запирающего экзамена блокирует всё (T7.11).
	 */
	public function resolveLesson( int $studentPersonId, GroupLessonDTO $groupLesson ): GateState {
		if ( $this->examLock->isLocked( $studentPersonId ) ) {
			return GateState::Locked;
		}

		if ( ! $this->accessPolicy->canRead( $studentPersonId, $groupLesson->id ) ) {
			return GateState::Locked;
		}

		if ( ! $this->dateReached( $groupLesson ) ) {
			return GateState::Locked;
		}

		return GateState::Available;
	}

	/**
	 * Доступен ли шаг урока: урок доступен + выполнен гейт-конфиг шага.
	 */
	public function resolveStep( int $studentPersonId, GroupLessonDTO $groupLesson, string $stepKey ): GateState {
		if ( ! $this->resolveLesson( $studentPersonId, $groupLesson )->isAvailable() ) {
			return GateState::Locked;
		}

		$lesson = $groupLesson->lessonId ? $this->lessons->get( $groupLesson->lessonId ) : null;
		if ( null === $lesson ) {
			return GateState::Locked;
		}

		$statuses = $this->progress->getStepStatuses( $studentPersonId, $groupLesson->id );

		return $this->resolveStepGate( $lesson->steps, $stepKey, $statuses );
	}

	/**
	 * @param \Inc\DTO\Course\StepDTO[]          $steps
	 * @param array<string, \Inc\Enums\Course\ProgressStatus> $statuses
	 */
	private function resolveStepGate( array $steps, string $stepKey, array $statuses ): GateState {
		$index = -1;
		foreach ( $steps as $i => $s ) {
			if ( $s->key === $stepKey ) {
				$index = $i;
				break;
			}
		}

		if ( $index < 0 ) {
			return GateState::Locked;
		}

		$gate = (string) ( $steps[ $index ]->payload['gate'] ?? 'none' );

		if ( 'sequential' === $gate ) {
			return 0 === $index
				? GateState::Available
				: $this->requireComplete( $statuses, $steps[ $index - 1 ]->key );
		}

		if ( str_starts_with( $gate, 'after:' ) ) {
			return $this->requireComplete( $statuses, substr( $gate, 6 ) );
		}

		return GateState::Available; // none / неизвестный конфиг — без гейта по выполнению
	}

	/**
	 * @param array<string, \Inc\Enums\Course\ProgressStatus> $statuses
	 */
	private function requireComplete( array $statuses, string $stepKey ): GateState {
		$status = $statuses[ $stepKey ] ?? null;

		return ( null !== $status && $status->isComplete() ) ? GateState::Available : GateState::Locked;
	}

	private function dateReached( GroupLessonDTO $groupLesson ): bool {
		if ( null === $groupLesson->scheduledAt || '' === $groupLesson->scheduledAt ) {
			return true;
		}

		return $groupLesson->scheduledAt <= $this->clock->now();
	}
}
