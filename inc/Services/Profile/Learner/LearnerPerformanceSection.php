<?php

declare( strict_types=1 );

namespace Inc\Services\Profile\Learner;

use Inc\DTO\Course\GradebookEntryDTO;
use Inc\DTO\Profile\LearnerContextDTO;
use Inc\Enums\Course\WorkSourceType;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\AttendanceRepository;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;
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
		private readonly GradebookService            $gradebook,
		private readonly AttendanceRepository        $attendance,
		private readonly SubmissionRepository        $submissions,
		private readonly AssessmentAttemptRepository $attempts,
		private readonly LessonManager               $lessons,
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
				// Ссылка на саму работу/попытку — та же страница, что открывает плеер
				// курса (контрольная — её permalink, обычная работа — шаг плеера).
				'url'         => $this->workUrl( $ctx, $e ),
			);
		}

		return $grades;
	}

	/**
	 * Ссылка на страницу работы/попытки — та же, что использует плеер курса
	 * (Bug: «не нажимается работа» — раньше клик открывал попап-сводку вместо
	 * перехода к реальной странице; теперь везде одна точка входа).
	 *
	 *  - Контрольная (`attempt`)  → permalink CPT `{subject}_assessments` +
	 *    `from_gid`/`from_gl` (см. `step-assessment.php`) — уже завершённая
	 *    попытка страница экзамена сама покажет экран результата.
	 *  - Обычная работа (`submission`) → плеер урока + `?step=<ключ шага>`
	 *    (тот же приём, что {@see LearnerScheduleSection::deadlines()}).
	 */
	private function workUrl( LearnerContextDTO $ctx, GradebookEntryDTO $e ): string {
		return match ( WorkSourceType::fromValueOrNull( $e->sourceType ) ) {
			WorkSourceType::Attempt    => $this->attemptUrl( $e ),
			WorkSourceType::Submission => $this->submissionUrl( $ctx, $e ),
			default                    => '',
		};
	}

	private function attemptUrl( GradebookEntryDTO $e ): string {
		$attempt = $this->attempts->find( $e->sourceId );
		if ( null === $attempt ) {
			return '';
		}

		$url = get_permalink( $attempt->assessmentId );
		if ( ! $url ) {
			return '';
		}

		$gid = $e->groupId;
		$gl  = (int) ( $e->groupLessonId ?? $attempt->groupLessonId ?? 0 );
		if ( $gid > 0 && $gl > 0 ) {
			$url = (string) add_query_arg( array( 'from_gid' => $gid, 'from_gl' => $gl ), $url );
		}

		return $url;
	}

	private function submissionUrl( LearnerContextDTO $ctx, GradebookEntryDTO $e ): string {
		if ( null === $e->groupLessonId ) {
			return '';
		}

		$sub = $this->submissions->find( $e->sourceId );
		if ( null === $sub ) {
			return '';
		}

		$row     = $ctx->rawRows[ $e->groupLessonId ] ?? null;
		$lesson  = $row?->lessonId ? $this->lessons->get( $row->lessonId ) : null;
		$baseUrl = (string) ( $ctx->lessonMap[ $e->groupLessonId ]['player_url'] ?? '' );
		$stepKey = $lesson?->stepKeyForWork( $sub->workId );

		return ( '' !== $baseUrl && $stepKey )
			? (string) add_query_arg( 'step', $stepKey, $baseUrl )
			: $baseUrl;
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
