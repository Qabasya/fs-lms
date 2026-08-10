<?php

declare( strict_types=1 );

namespace Inc\Services\Profile\Learner;

use Inc\DTO\Profile\LearnerContextDTO;
use Inc\Repositories\WPDBRepositories\AttendanceRepository;
use Inc\Services\Course\GradebookService;

/**
 * Class LearnerPerformanceSection
 *
 * Секция кабинета ученика: дневник (сырые баллы, D4), последние оценки
 * и посещаемость (бинарно + %).
 *
 * Выделена из LearnerService (Т14.3).
 *
 * @package Inc\Services\Profile\Learner
 */
class LearnerPerformanceSection {

	public function __construct(
		private readonly GradebookService     $gradebook,
		private readonly AttendanceRepository $attendance,
	) {}

	/**
	 * Дневник ученика (сырые баллы).
	 *
	 * @param LearnerContextDTO $ctx      Контекст сборки
	 * @param int               $personId Физлицо ученика
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function grades( LearnerContextDTO $ctx, int $personId ): array {
		$grades = array();

		foreach ( $this->gradebook->forStudent( $personId ) as $e ) {
			$grades[] = array(
				'title'       => $e->title,
				'category'    => $e->category,
				'type'        => $e->badge?->label() ?? '', // Домашнее задание / Практическая / Контрольная / Экзамен…
				'group_key'   => $e->groupKey ?? ( $e->sourceType . ':' . $e->sourceId ), // группировка попыток одной работы
				'value'       => $e->displayValue(),
				'display'     => $e->displayType,
				'graded_at'   => $e->gradedAt,
				'group_name'  => $ctx->groups[ $e->groupId ]['name'] ?? '',
				// #12: источник результата — для перехода к деталям работы/попытки.
				'source_type' => $e->sourceType,
				'source_id'   => $e->sourceId,
			);
		}

		return $grades;
	}

	/**
	 * Последние оценённые работы (свежие сверху).
	 *
	 * @param array<int, array<string, mixed>> $grades Дневник
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function recentGrades( array $grades ): array {
		$recent = array_values( array_filter( $grades, static fn( $g ) => ! empty( $g['graded_at'] ) ) );
		usort( $recent, static fn( $a, $b ) => strcmp( (string) $b['graded_at'], (string) $a['graded_at'] ) );

		return $recent;
	}

	/**
	 * Посещаемость: бинарные отметки + процент.
	 *
	 * @param LearnerContextDTO $ctx      Контекст сборки
	 * @param int               $personId Физлицо ученика
	 *
	 * @return array{rows: array, present: int, total: int, percent: int|null}
	 */
	public function attendance( LearnerContextDTO $ctx, int $personId ): array {
		$rows    = array();
		$present = 0;
		$total   = 0;

		foreach ( $this->attendance->listByStudent( $personId ) as $a ) {
			$lesson = $ctx->lessonMap[ $a->groupLessonId ] ?? null;
			$rows[] = array(
				'date'    => $lesson ? $lesson['date'] : substr( $a->markedAt, 0, 10 ),
				'topic'   => $lesson ? $lesson['topic'] : '—',
				'course'  => $lesson ? ( $lesson['course'] ?? '' ) : '',
				'present' => $a->isPresent,
			);
			++$total;
			if ( $a->isPresent ) {
				++$present;
			}
		}

		usort( $rows, static fn( $a, $b ) => strcmp( (string) $b['date'], (string) $a['date'] ) );

		return array(
			'rows'    => $rows,
			'present' => $present,
			'total'   => $total,
			'percent' => $total > 0 ? (int) round( $present / $total * 100 ) : null,
		);
	}
}
