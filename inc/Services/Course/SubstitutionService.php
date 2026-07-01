<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\SubstitutionRepository;

/**
 * Управление заменами преподавателя (Эпик 5, D5).
 *
 * Назначает офис (`Capability::ManageSchedule`). `groups.teacher_id` НЕ трогаем —
 * запоминаем текущего препода как `original_teacher_id` на момент создания grant.
 *
 * @package Inc\Services\Course
 */
class SubstitutionService {

	public function __construct(
		private readonly SubstitutionRepository $repo,
		private readonly GroupsRepository       $groups,
	) {}

	/**
	 * Создаёт замену на период.
	 *
	 * @param string $validFrom 'Y-m-d'.
	 * @param string $validTo   'Y-m-d'.
	 *
	 * @throws \InvalidArgumentException при неверных данных.
	 */
	public function assign(
		int     $groupId,
		int     $substituteTeacherId,
		string  $validFrom,
		string  $validTo,
		?string $reason,
		int     $approvedBy
	): int {
		$group = $this->groups->findById( $groupId );
		if ( ! $group ) {
			throw new \InvalidArgumentException( 'Группа не найдена.' );
		}
		if ( $substituteTeacherId <= 0 ) {
			throw new \InvalidArgumentException( 'Не указан замещающий преподаватель.' );
		}
		if ( ! $this->isValidDate( $validFrom ) || ! $this->isValidDate( $validTo ) ) {
			throw new \InvalidArgumentException( 'Неверный формат даты (ожидается ГГГГ-ММ-ДД).' );
		}
		if ( $validFrom > $validTo ) {
			throw new \InvalidArgumentException( 'Дата начала позже даты окончания.' );
		}

		return $this->repo->create( array(
			'group_id'              => $groupId,
			'original_teacher_id'   => null !== $group->teacher_id ? (int) $group->teacher_id : null,
			'substitute_teacher_id' => $substituteTeacherId,
			'valid_from'            => $validFrom,
			'valid_to'              => $validTo,
			'reason'                => $reason,
			'approved_by'           => $approvedBy,
		) );
	}

	public function revoke( int $id ): void {
		$this->repo->delete( $id );
	}

	/** @return \Inc\DTO\Course\SubstitutionDTO[] */
	public function listByGroup( int $groupId ): array {
		return $this->repo->listByGroup( $groupId );
	}

	private function isValidDate( string $date ): bool {
		$d = \DateTimeImmutable::createFromFormat( 'Y-m-d', $date );
		return $d && $d->format( 'Y-m-d' ) === $date;
	}
}
