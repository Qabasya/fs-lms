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
use Inc\Services\Profile\Learner\LearnerRankingSection;

/** Reuses the learner ranking calculation and filters groups only after assigning places. */
class SubjectRankingService {

	/** Receives access rules, names and the shared ranking calculation. */
	public function __construct(
		private readonly RankingAccessService $access,
		private readonly LearnerRankingRepository $repository,
		private readonly LearnerRankingSection $ranking,
		private readonly ClockInterface $clock,
	) {}

	/**
	 * Server authorization applies to every subject request, including manipulated UI selections.
	 *
	 * @throws \DomainException The staff member cannot view this subject.
	 * @throws \InvalidArgumentException The group does not belong to this direction.
	 */
	public function build( int $userId, string $subject, int $groupId = 0 ): array {
		$allowed = $this->access->subjectsFor( $userId );
		if ( ! in_array( $subject, array_column( $allowed, 'key' ), true ) ) {
			throw new \DomainException( 'Нет доступа к рейтингу этого направления.' );
		}
		$now    = $this->clock->now( 'mysql' );
		$period = $this->ranking->period( $now );
		if ( null === $period ) {
			return array(
				'subject_key' => $subject,
				'period'      => null,
				'rows'        => array(),
				'groups'      => array(),
				'summer'      => true,
			);
		}
		$scores   = $this->ranking->scoresForSubject( $subject, $now );
		$students = array();
		$groups   = array();
		foreach ( $this->repository->roster( $subject ) as $record ) {
			$id  = (int) $record['student_person_id'];
			$gid = (int) $record['group_id'];
			if ( ! isset( $scores[ $id ] ) ) {
				continue;
			}
			$groups[ $gid ] = array(
				'id'   => $gid,
				'name' => (string) $record['group_name'],
			);
			if ( ! isset( $students[ $id ] ) ) {
				$students[ $id ] = array(
					'person_id' => $id,
					'name'      => trim( implode( ' ', array_filter( array( $record['snapshot_last_name'], $record['snapshot_first_name'], $record['snapshot_middle_name'] ?? '' ) ) ) ),
					'groups'    => array(),
					'score'     => $scores[ $id ]['score'],
					'rank'      => $scores[ $id ]['rank'],
				);
			}
			$students[ $id ]['groups'][ $gid ] = $groups[ $gid ];
		}
		if ( $groupId < 0 || ( $groupId > 0 && ! isset( $groups[ $groupId ] ) ) ) {
			throw new \InvalidArgumentException( 'Группа не входит в выбранное направление.' );
		}
		$rows = array_values( array_filter( $students, static fn( array $student ): bool => 0 === $groupId || isset( $student['groups'][ $groupId ] ) ) );
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
		}
		unset( $row );
		return array(
			'subject_key' => $subject,
			'period'      => $period['label'],
			'rows'        => $rows,
			'groups'      => array_values( $groups ),
			'summer'      => false,
		);
	}
}
