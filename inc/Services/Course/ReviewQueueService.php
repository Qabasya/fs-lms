<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\DTO\Assessment\AttemptDTO;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Managers\Course\WorkManager;
use Inc\Repositories\WPDBRepositories\AssessmentAnswerRepository;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;

/**
 * Class ReviewQueueService
 *
 * Read-модель вкладки «Работы» (D3, .docs/Tasks.md) — объединяет обычные работы
 * (`submissions`, сгруппированные по `work_id`) и экзамены (`assessment_attempts`,
 * сгруппированные по `assessment_id`) по группам пользователя (свои + активные
 * замены; офис — все, тот же набор, что {@see \Inc\Services\Profile\DashboardService::collectGroups()}),
 * в три корзины: «На проверке» (pending), «Ждут подтверждения» (confirm),
 * «Проверенные» (done).
 *
 * @package Inc\Services\Course
 */
class ReviewQueueService {

	public function __construct(
		private readonly GroupsRepository            $groups,
		private readonly TeacherGroupResolver        $teacherGroups,
		private readonly SubmissionRepository        $submissions,
		private readonly AssessmentAttemptRepository $attempts,
		private readonly AssessmentAnswerRepository  $answers,
		private readonly WorkManager                 $works,
		private readonly AssessmentManager           $assessments,
		private readonly GroupLessonRepository        $groupLessons,
		private readonly StudentRecordRepository     $studentRecords,
	) {}

	/**
	 * Работы/экзамены активной вкладки — по одной строке на работу/экзамен
	 * (не на сдачу): группировка снимает необходимость обходить учеников по одному.
	 *
	 * @param int    $userId    ID текущего пользователя (wp user id)
	 * @param bool   $allGroups офис видит все группы, препод — свои + замены
	 * @param string $tab       pending|confirm|done
	 *
	 * `latest_at` — MAX(submitted_at) среди сдач корзины, для клиентской
	 * сортировки «сначала новые/старые» (D3).
	 *
	 * @return array<int, array{source_type:string, source_id:int, title:string, label:string, count:int, group_ids:int[], latest_at:?string}>
	 */
	public function pendingWorks( int $userId, bool $allGroups, string $tab ): array {
		$groupIds = $this->teacherGroups->idsFor( $userId, $allGroups );
		if ( empty( $groupIds ) ) {
			return array();
		}

		$items = array();

		// Обычные работы — только pending/done: у submissions нет отдельного шага
		// подтверждения (confirm — свойство экзаменов без поштучной ручной проверки).
		if ( 'confirm' !== $tab ) {
			$statuses = 'pending' === $tab ? array( 'submitted' ) : array( 'graded' );
			$byWork   = array();
			foreach ( $this->submissions->summaryByGroups( $groupIds, $statuses ) as $row ) {
				$wid = $row['work_id'];
				if ( ! isset( $byWork[ $wid ] ) ) {
					$byWork[ $wid ] = array( 'count' => 0, 'group_ids' => array(), 'work_type' => $row['work_type'], 'latest_at' => null );
				}
				$byWork[ $wid ]['count']      += $row['cnt'];
				$byWork[ $wid ]['group_ids'][] = $row['group_id'];
				if ( null !== $row['latest_at'] && ( null === $byWork[ $wid ]['latest_at'] || $row['latest_at'] > $byWork[ $wid ]['latest_at'] ) ) {
					$byWork[ $wid ]['latest_at'] = $row['latest_at'];
				}
			}
			foreach ( $byWork as $workId => $agg ) {
				$work = $this->works->get( $workId );
				if ( null === $work ) {
					continue;
				}
				$items[] = array(
					'source_type' => 'work',
					'source_id'   => $workId,
					'title'       => $work->title,
					'label'       => $work->workType->label(),
					'count'       => $agg['count'],
					'group_ids'   => array_values( array_unique( $agg['group_ids'] ) ),
					'latest_at'   => $agg['latest_at'],
				);
			}
		}

		// Экзамены — pending/confirm/done по attemptBucket().
		$byAssessment = array();
		foreach ( $this->attempts->listByGroupsForGradebook( $groupIds ) as $attempt ) {
			if ( $this->attemptBucket( $attempt ) !== $tab ) {
				continue;
			}
			$aid = $attempt->assessmentId;
			if ( ! isset( $byAssessment[ $aid ] ) ) {
				$byAssessment[ $aid ] = array( 'count' => 0, 'group_ids' => array(), 'latest_at' => null );
			}
			++$byAssessment[ $aid ]['count'];
			if ( $attempt->groupId ) {
				$byAssessment[ $aid ]['group_ids'][] = $attempt->groupId;
			}
			if ( null !== $attempt->submittedAt
				&& ( null === $byAssessment[ $aid ]['latest_at'] || $attempt->submittedAt > $byAssessment[ $aid ]['latest_at'] ) ) {
				$byAssessment[ $aid ]['latest_at'] = $attempt->submittedAt;
			}
		}
		foreach ( $byAssessment as $assessmentId => $agg ) {
			$assessment = $this->assessments->get( $assessmentId );
			if ( null === $assessment ) {
				continue;
			}
			$items[] = array(
				'source_type' => 'assessment',
				'source_id'   => $assessmentId,
				'title'       => $assessment->title,
				'label'       => 'Экзамен',
				'count'       => $agg['count'],
				'group_ids'   => array_values( array_unique( $agg['group_ids'] ) ),
				'latest_at'   => $agg['latest_at'],
			);
		}

		return $items;
	}

	/**
	 * Сдачи конкретной работы/экзамена в разрезе активной вкладки — ученик,
	 * группа, дата сдачи, `source_type`/`source_id` уже самой СДАЧИ (для перехода
	 * на деталь работы, D2 — у работы с несколькими учениками у каждого свой
	 * `submission_id`/`attempt_id`).
	 *
	 * @return array<int, array{source_type:string, source_id:int, student_name:string, group_id:int, group_name:string, submitted_at:?string}>
	 */
	public function submissionsFor( string $sourceType, int $sourceId, int $userId, bool $allGroups, string $tab ): array {
		$groupIds = $this->teacherGroups->idsFor( $userId, $allGroups );
		if ( empty( $groupIds ) ) {
			return array();
		}

		if ( 'work' === $sourceType ) {
			if ( 'confirm' === $tab ) {
				return array();
			}
			$statuses = 'pending' === $tab ? array( 'submitted' ) : array( 'graded' );
			$out      = array();
			foreach ( $this->submissions->listByWorkAndGroups( $sourceId, $groupIds, $statuses ) as $sub ) {
				$gl = $this->groupLessons->find( $sub->groupLessonId );
				if ( null === $gl ) {
					continue;
				}
				$group = $this->groups->findById( $gl->groupId );
				$out[] = array(
					'source_type'  => 'submission',
					'source_id'    => $sub->id,
					'student_name' => $this->studentName( $sub->studentPersonId, $gl->groupId ),
					'group_id'     => $gl->groupId,
					'group_name'   => $group->name ?? '',
					'submitted_at' => $sub->submittedAt,
				);
			}
			return $out;
		}

		if ( 'assessment' === $sourceType ) {
			$out = array();
			foreach ( $this->attempts->listByGroupsForGradebook( $groupIds ) as $attempt ) {
				if ( $attempt->assessmentId !== $sourceId || $this->attemptBucket( $attempt ) !== $tab ) {
					continue;
				}
				$group = $attempt->groupId ? $this->groups->findById( $attempt->groupId ) : null;
				$out[] = array(
					'source_type'  => 'attempt',
					'source_id'    => $attempt->id,
					'student_name' => $this->studentName( $attempt->studentPersonId, $attempt->groupId ?? 0 ),
					'group_id'     => $attempt->groupId ?? 0,
					'group_name'   => $group->name ?? '',
					'submitted_at' => $attempt->submittedAt,
				);
			}
			return $out;
		}

		return array();
	}

	/**
	 * Корзина попытки: `pending` — есть неоценённые задания (ручная проверка
	 * ещё не завершена); `confirm` — уже полностью оценена автоматически, но для
	 * видов без поштучной ручной проверки (ЕГЭ-компьютерный, D18) нужен явный
	 * шаг «Утвердить работу» (`approved_at IS NULL`); иначе — `done`.
	 */
	private function attemptBucket( AttemptDTO $attempt ): string {
		if ( $this->answers->hasPendingAnswers( $attempt->id ) ) {
			return 'pending';
		}

		$assessment    = $this->assessments->get( $attempt->assessmentId );
		$needsApproval = null !== $assessment && AssessmentKind::EgeComputer === $assessment->kind;

		return ( $needsApproval && null === $attempt->approvedAt ) ? 'confirm' : 'done';
	}

	private function studentName( int $studentPersonId, int $groupId ): string {
		$records = $groupId > 0 ? $this->studentRecords->findAllByStudentAndGroup( $studentPersonId, $groupId ) : array();
		$record  = $records[0] ?? null;

		return $record ? trim( $record->snapshotLastName . ' ' . $record->snapshotFirstName ) : '';
	}
}
