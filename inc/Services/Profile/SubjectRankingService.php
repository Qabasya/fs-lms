<?php
/**
 * Staff subject ranking table.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Services\Profile;

use Inc\Contracts\ClockInterface;
use Inc\Repositories\WPDBRepositories\LearnerRankingRepository;


/**
 * Reuses the learner ranking calculation. «Все группы» — общие места направления (эталон
 * плитки ученика); выбранная группа — места пересчитаны плотно внутри неё.
 */
class SubjectRankingService {

	/** Receives access rules, names and the shared ranking calculation. */
	public function __construct(
		private readonly RankingAccessService $access,
		private readonly LearnerRankingRepository $repository,
		private readonly RankingCalculationService $ranking,
		private readonly ClockInterface $clock,
		private readonly RankingSnapshotService $snapshots,
	) {}

	/**
	 * Server authorization applies to every subject request, including manipulated UI selections.
	 *
	 * @param int    $groupId    0 — все группы направления.
	 * @param string $periodFrom Граница периода (`from` из {@see RankingCalculationService::periodsFor()});
	 *                           пусто — текущий период.
	 *
	 * @throws \DomainException        The staff member cannot view this subject.
	 * @throws \InvalidArgumentException The group does not belong to this direction / the period is not selectable.
	 */
	public function build( int $userId, string $subject, int $groupId = 0, string $periodFrom = '' ): array {
		return $this->buildWith( $this->access->subjectsFor( $userId ), $subject, $groupId, $periodFrom );
	}

	/**
	 * Рейтинг для ученика: те же места и фильтры, но направления ограничены его активными группами.
	 *
	 * @param int $personId Физлицо ученика (НЕ wp_user_id).
	 */
	public function buildForLearner( int $personId, string $subject, int $groupId = 0, string $periodFrom = '' ): array {
		$result = $this->buildWith( $this->access->learnerSubjectsFor( $personId ), $subject, $groupId, $periodFrom, $personId );
		$result['viewer_person_id'] = $personId;
		return $result;
	}

	/**
	 * Общая сборка таблицы: список разрешённых направлений приходит от роли смотрящего.
	 *
	 * @throws \DomainException         Направление недоступно смотрящему.
	 * @throws \InvalidArgumentException Группа не входит в направление / период недоступен.
	 */
	private function buildWith( array $allowed, string $subject, int $groupId, string $periodFrom, int $viewerPersonId = 0 ): array {
		if ( ! in_array( $subject, array_column( $allowed, 'key' ), true ) ) {
			throw new \DomainException( 'Нет доступа к рейтингу этого направления.' );
		}
		$now     = $this->clock->now( 'mysql' );
		$periods = $this->ranking->periodsFor( $now );
		$period  = null;
		if ( '' === $periodFrom ) {
			$period = $this->ranking->period( $now );
		} else {
			foreach ( $periods as $candidate ) {
				if ( $candidate['from'] === $periodFrom ) {
					$period = $candidate;
					break;
				}
			}
			if ( null === $period ) {
				throw new \InvalidArgumentException( 'Период недоступен.' );
			}
		}
		$policy = $this->ranking->subjectPolicy( $subject );
		$scores   = $this->ranking->scoresForSubject( $subject, $now, $period, true, $policy );
		$students = array();
		$groups   = array();
		$viewerGroupId = 0;
		$subjectName = (string) ( array_column( $allowed, 'name', 'key' )[ $subject ] ?? '' );
		foreach ( $this->repository->roster( $subject ) as $record ) {
			$id  = (int) $record['student_person_id'];
			$gid = (int) $record['group_id'];
			$individual = ( $record['access_mode'] ?? '' ) === \Inc\Enums\Course\AccessMode::Individual->value;
			if ( ! isset( $scores[ $id ] ) ) {
				continue;
			}
			if ( ! $individual && $id === $viewerPersonId && 0 === $viewerGroupId ) {
				$viewerGroupId = $gid;
			}
			if ( ! $individual ) {
				$groups[ $gid ] = array(
				'id'      => $gid,
				'name'    => (string) $record['group_name'],
				// Имя предмета кнопке выбора группы (тот же вид, что в «Сводке по ученику»).
				'subject' => $subjectName,
				);
			}
			if ( ! isset( $students[ $id ] ) ) {
				$students[ $id ] = array(
					'person_id' => $id,
					// Фамилия и имя, без отчества — как в журнале.
					'name'      => trim( $record['snapshot_last_name'] . ' ' . $record['snapshot_first_name'] ),
					'groups'    => array(),
					'score'     => $scores[ $id ]['score'],
					'rank'      => $scores[ $id ]['rank'],
				);
				if ( 0 === $viewerPersonId || $id === $viewerPersonId ) {
					$students[ $id ]['breakdown'] = $scores[ $id ]['breakdown'] ?? array();
					$students[ $id ]['max_score'] = $scores[ $id ]['max_score'] ?? null;
					$students[ $id ]['max_unknown'] = $scores[ $id ]['max_unknown'] ?? false;
					$students[ $id ]['score_unknown'] = $scores[ $id ]['score_unknown'] ?? false;
				}
			}
			if ( ! $individual ) {
				$students[ $id ]['groups'][ $gid ] = $groups[ $gid ];
			}
		}
		if ( $groupId < 0 || ( $groupId > 0 && ! isset( $groups[ $groupId ] ) ) ) {
			throw new \InvalidArgumentException( 'Группа не входит в выбранное направление.' );
		}
		$rows = array_values( array_filter( $students, static fn( array $student ): bool => 0 === $groupId || isset( $student['groups'][ $groupId ] ) ) );
		if ( $groupId > 0 ) {
			// Выбрана группа — места пересчитываются плотно внутри неё (свой рейтинг группы);
			// «Все группы» оставляет общие места направления (эталон плитки ученика).
			$ranks = $this->ranking->rankTotals( array_column( $rows, 'score', 'person_id' ) );
			foreach ( $rows as &$row ) {
				$row['rank'] = $ranks[ $row['person_id'] ]['rank'];
			}
			unset( $row );
		}
		usort( $rows, static function ( array $a, array $b ): int {
			$scoreOrder = $b['score'] <=> $a['score'];
			if ( 0 !== $scoreOrder ) {
				return $scoreOrder;
			}
			$nameOrder = strcmp( mb_strtolower( $a['name'] ), mb_strtolower( $b['name'] ) );
			return 0 !== $nameOrder ? $nameOrder : ( $a['person_id'] <=> $b['person_id'] );
		} );
		foreach ( $rows as &$row ) {
			$row['groups'] = array_values( $row['groups'] );
			$row['trend'] = $this->snapshots->trend( $subject, $groupId, $row['person_id'], $row['rank'], $period['from'], $now, $policy );
		}
		unset( $row );
		return array(
			'subject_key'  => $subject,
			'period'       => $period['label'],
			'period_from'  => $period['from'],
			'periods'      => $periods,
			'rows'         => $rows,
			'groups'       => array_values( $groups ),
			'viewer_group_id' => $viewerGroupId,
		);
	}
}
