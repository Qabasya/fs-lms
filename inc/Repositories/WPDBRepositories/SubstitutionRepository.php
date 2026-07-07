<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\DTO\Course\SubstitutionDTO;
use Inc\Enums\Settings\TableName;

/**
 * Хранилище замен преподавателя (fs_lms_substitutions, Эпик 5).
 *
 * @package Inc\Repositories\WPDBRepositories
 */
class SubstitutionRepository {

	private \wpdb  $wpdb;
	private string $table;

	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb  = $wpdb ?? $GLOBALS['wpdb'];
		$this->table = TableName::Substitutions->prefixed();
	}

	/**
	 * @param array{group_id:int, original_teacher_id:?int, substitute_teacher_id:int, valid_from:string, valid_to:string, reason:?string, approved_by:?int} $data
	 */
	public function create( array $data ): int {
		$this->wpdb->insert(
			$this->table,
			array(
				'group_id'              => (int) $data['group_id'],
				'original_teacher_id'   => $data['original_teacher_id'] ?? null,
				'substitute_teacher_id' => (int) $data['substitute_teacher_id'],
				'valid_from'            => $data['valid_from'],
				'valid_to'              => $data['valid_to'],
				'reason'                => $data['reason'] ?? null,
				'approved_by'           => $data['approved_by'] ?? null,
			)
		);
		return (int) $this->wpdb->insert_id;
	}

	public function find( int $id ): ?SubstitutionDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', $this->table, $id ),
			ARRAY_A
		);
		return $row ? SubstitutionDTO::fromArray( $row ) : null;
	}

	/** Все замены группы (для админ-списка), новейшие сверху. */
	public function listByGroup( int $groupId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE group_id = %d ORDER BY valid_from DESC',
				$this->table,
				$groupId
			),
			ARRAY_A
		);
		return array_map( array( SubstitutionDTO::class, 'fromArray' ), $rows ?: array() );
	}

	/** Активная замена группы на дату (или null). */
	public function findActiveForGroup( int $groupId, string $date ): ?SubstitutionDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE group_id = %d AND valid_from <= %s AND valid_to >= %s ORDER BY valid_from DESC LIMIT 1',
				$this->table,
				$groupId,
				$date,
				$date
			),
			ARRAY_A
		);
		return $row ? SubstitutionDTO::fromArray( $row ) : null;
	}

	/** Активные замены, где пользователь — замещающий, на дату (для «Главной» замещающего). */
	public function findActiveBySubstitute( int $userId, string $date ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE substitute_teacher_id = %d AND valid_from <= %s AND valid_to >= %s ORDER BY valid_to ASC',
				$this->table,
				$userId,
				$date,
				$date
			),
			ARRAY_A
		);
		return array_map( array( SubstitutionDTO::class, 'fromArray' ), $rows ?: array() );
	}

	/**
	 * Есть ли у пользователя активный grant на группу СЕГОДНЯ (для GroupAccessGuard).
	 * Дата берётся из БД (`CURDATE()`), чтобы доступ гас по `valid_to` без ручной правки.
	 */
	public function hasActiveGrant( int $userId, int $groupId ): bool {
		return (bool) $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT 1 FROM %i WHERE substitute_teacher_id = %d AND group_id = %d AND valid_from <= CURDATE() AND valid_to >= CURDATE() LIMIT 1',
				$this->table,
				$userId,
				$groupId
			)
		);
	}

	public function delete( int $id ): bool {
		return (bool) $this->wpdb->delete( $this->table, array( 'id' => $id ) );
	}

	/** Каскадная очистка при удалении группы (GroupDeletionHandler). */
	public function deleteAllByGroup( int $groupId ): int {
		return (int) $this->wpdb->delete( $this->table, array( 'group_id' => $groupId ) );
	}
}
