<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Contracts\ClockInterface;
use Inc\Enums\Profile\NotificationType;
use Inc\Enums\Wp\PageRoutes;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\SubstitutionRepository;
use Inc\Services\Profile\NotificationService;

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
		private readonly NotificationService    $notifications,
		private readonly ClockInterface         $clock,
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

		$originalTeacherId = null !== $group->teacher_id ? (int) $group->teacher_id : null;

		// Замещать самого себя бессмысленно: grant не изменит ничего, но создаст
		// в журнале запись «вместо себя».
		if ( null !== $originalTeacherId && $originalTeacherId === $substituteTeacherId ) {
			throw new \InvalidArgumentException( 'Замещающий совпадает с преподавателем группы.' );
		}

		$today = substr( $this->clock->now(), 0, 10 );
		if ( $validTo < $today ) {
			throw new \InvalidArgumentException( 'Период замены уже прошёл — выберите дату не раньше сегодняшней.' );
		}

		$overlap = $this->repo->findOverlapping( $groupId, $validFrom, $validTo );
		if ( $overlap ) {
			throw new \InvalidArgumentException( sprintf(
				'Период пересекается с уже назначенной заменой (%s — %s). Снимите её или измените даты.',
				$this->formatDate( $overlap->validFrom ),
				$this->formatDate( $overlap->validTo )
			) );
		}

		$id = $this->repo->create( array(
			'group_id'              => $groupId,
			'original_teacher_id'   => $originalTeacherId,
			'substitute_teacher_id' => $substituteTeacherId,
			'valid_from'            => $validFrom,
			'valid_to'              => $validTo,
			'reason'                => $reason,
			'approved_by'           => $approvedBy,
		) );

		$this->notifications->push(
			array( $substituteTeacherId ),
			NotificationType::SubstituteAssigned,
			"sub:{$id}",
			array(
				'group_name' => (string) ( $group->name ?? '' ),
				'valid_from' => $validFrom,
				'valid_to'   => $validTo,
				'reason'     => $reason,
			),
			(string) add_query_arg( array( 'screen' => 'dashboard' ), PageRoutes::UserProfile->url() ),
			$groupId,
			'substitution',
			$id
		);

		$this->notifications->push(
			$this->notifications->groupStudentUserIds( $groupId ),
			NotificationType::SubstituteAssignedStudent,
			"sub-student:{$id}",
			array(
				'group_name'   => (string) ( $group->name ?? '' ),
				'teacher_name' => get_userdata( $substituteTeacherId )->display_name ?? '',
				'valid_from'   => $validFrom,
				'valid_to'     => $validTo,
			),
			(string) PageRoutes::UserProfile->url(),
			$groupId,
			'substitution',
			$id
		);

		return $id;
	}

	public function revoke( int $id ): void {
		$substitution = $this->repo->find( $id );
		if ( $substitution ) {
			$this->notifications->retract(
				array( $substitution->substituteTeacherId ),
				"sub:{$id}"
			);
			$this->notifications->retract(
				$this->notifications->groupStudentUserIds( $substitution->groupId ),
				"sub-student:{$id}"
			);
		}

		$this->repo->delete( $id );
	}

	/** @return \Inc\DTO\Course\SubstitutionDTO[] */
	public function listByGroup( int $groupId ): array {
		return $this->repo->listByGroup( $groupId );
	}

	/** 'Y-m-d' → 'd.m.Y' для текста ошибки. */
	private function formatDate( string $date ): string {
		$d = \DateTimeImmutable::createFromFormat( 'Y-m-d', substr( $date, 0, 10 ) );
		return $d ? $d->format( 'd.m.Y' ) : $date;
	}

	private function isValidDate( string $date ): bool {
		$d = \DateTimeImmutable::createFromFormat( 'Y-m-d', $date );
		return $d && $d->format( 'Y-m-d' ) === $date;
	}
}
