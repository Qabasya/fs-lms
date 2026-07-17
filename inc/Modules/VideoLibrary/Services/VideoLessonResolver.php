<?php

declare( strict_types=1 );

namespace Inc\Modules\VideoLibrary\Services;

use Inc\DTO\Course\GroupLessonDTO;
use Inc\Enums\Course\LessonStatus;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;

/**
 * Class VideoLessonResolver
 *
 * Резолв занятия по времени начала записи (FS_LMS_API.md §7.2). Кандидаты — занятия
 * того же календарного дня: групповая ветка (`group_id`) — все занятия группы (включая
 * `kind='individual'` этой группы — покрывает «индивидуальную запись положили в папку группы»);
 * индивидуальная ветка (`teacher_user_id`) — `kind='individual'` занятия преподавателя
 * по всем его группам. `cancelled` исключаются.
 *
 * Матчится только попадание в окно [scheduled_at − 45 мин; ends_at + 45 мин]
 * (`ends_at IS NULL` → scheduled_at + 3 ч); из нескольких — минимум |recorded − scheduled|,
 * строгая ничья — неоднозначность (unmatched, ручная привязка).
 *
 * @package Inc\Modules\VideoLibrary\Services
 */
class VideoLessonResolver {

	public const REASON_MATCHED       = 'matched';
	public const REASON_NO_CANDIDATES = 'no_candidates';
	public const REASON_AMBIGUOUS     = 'ambiguous';

	/** Допуск до начала и после конца занятия. */
	private const WINDOW_SEC = 45 * MINUTE_IN_SECONDS;

	/** Длительность занятия без ends_at. */
	private const DEFAULT_DURATION_SEC = 3 * HOUR_IN_SECONDS;

	public function __construct(
		private readonly GroupLessonRepository $lessons,
	) {}

	/**
	 * @param \DateTimeImmutable $recordedAt    Начало записи, нормализовано к TZ сайта
	 *                                          (scheduled_at в БД — локальный wall-clock той же TZ).
	 * @param int|null           $groupId       Групповая ветка (`lms.group_id`).
	 * @param int|null           $teacherUserId Индивидуальная ветка (резолвнутый `teacher_username`).
	 *
	 * @return array{group_lesson_id: int|null, reason: string}
	 */
	public function resolve( \DateTimeImmutable $recordedAt, ?int $groupId, ?int $teacherUserId ): array {
		$day = $recordedAt->format( 'Y-m-d' );

		if ( null !== $groupId && $groupId > 0 ) {
			$candidates = $this->lessons->listByGroupAndDay( $groupId, $day );
		} elseif ( null !== $teacherUserId && $teacherUserId > 0 ) {
			$candidates = $this->lessons->listIndividualByTeacherAndDay( $teacherUserId, $day );
		} else {
			$candidates = array();
		}

		$recordedTs = $recordedAt->getTimestamp();
		$tz         = $recordedAt->getTimezone();

		$best         = null;
		$bestDistance = PHP_INT_MAX;
		$ambiguous    = false;

		foreach ( $candidates as $lesson ) {
			if ( null === $lesson->scheduledAt || LessonStatus::Cancelled->value === $lesson->status ) {
				continue;
			}
			if ( ! $this->inWindow( $lesson, $recordedTs, $tz ) ) {
				continue;
			}

			$distance = abs( $recordedTs - $this->wallClockTs( $lesson->scheduledAt, $tz ) );
			if ( $distance < $bestDistance ) {
				$best         = $lesson;
				$bestDistance = $distance;
				$ambiguous    = false;
			} elseif ( $distance === $bestDistance ) {
				$ambiguous = true;
			}
		}

		if ( null === $best ) {
			return array(
				'group_lesson_id' => null,
				'reason'          => self::REASON_NO_CANDIDATES,
			);
		}
		if ( $ambiguous ) {
			return array(
				'group_lesson_id' => null,
				'reason'          => self::REASON_AMBIGUOUS,
			);
		}

		return array(
			'group_lesson_id' => $best->id,
			'reason'          => self::REASON_MATCHED,
		);
	}

	private function inWindow( GroupLessonDTO $lesson, int $recordedTs, \DateTimeZone $tz ): bool {
		$startTs = $this->wallClockTs( (string) $lesson->scheduledAt, $tz );
		$endTs   = null !== $lesson->endsAt && '' !== $lesson->endsAt
			? $this->wallClockTs( $lesson->endsAt, $tz )
			: $startTs + self::DEFAULT_DURATION_SEC;

		return $recordedTs >= $startTs - self::WINDOW_SEC
			&& $recordedTs <= $endTs + self::WINDOW_SEC;
	}

	private function wallClockTs( string $datetime, \DateTimeZone $tz ): int {
		return ( new \DateTimeImmutable( $datetime, $tz ) )->getTimestamp();
	}
}
