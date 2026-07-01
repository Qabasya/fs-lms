<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories\Log;

use Inc\DTO\Log\LearningEventDTO;
use Inc\DTO\Log\LearningEventInputDTO;
use Inc\Enums\Log\LogChannel;

class LearningEventRepository {

	private \wpdb  $wpdb;
	private string $table;

	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb  = $wpdb ?? $GLOBALS['wpdb'];
		$this->table = LogChannel::LearningEvents->tableName()->prefixed();
	}

	public function create( LearningEventInputDTO $dto ): int {
		$this->wpdb->insert( $this->table, $dto->toArray() );
		return (int) $this->wpdb->insert_id;
	}

	/** @return LearningEventDTO[] */
	public function listByGroup( int $groupId, int $page, int $perPage ): array {
		$offset = ( $page - 1 ) * $perPage;
		$rows   = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE group_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d',
				$this->table,
				$groupId,
				$perPage,
				$offset
			),
			ARRAY_A
		);
		return array_map( [ LearningEventDTO::class, 'fromArray' ], $rows ?: array() );
	}

	/** Срез ученика/родителя: публичные события + свои. @return LearningEventDTO[] */
	public function listByGroupPublic( int $groupId, int $actorUserId, int $page, int $perPage ): array {
		$offset = ( $page - 1 ) * $perPage;
		$rows   = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE group_id = %d AND (is_public = 1 OR actor_user_id = %d)
				 ORDER BY created_at DESC LIMIT %d OFFSET %d',
				$this->table,
				$groupId,
				$actorUserId,
				$perPage,
				$offset
			),
			ARRAY_A
		);
		return array_map( [ LearningEventDTO::class, 'fromArray' ], $rows ?: array() );
	}

	/** @return LearningEventDTO[] */
	public function listByActor( int $actorUserId, int $page, int $perPage ): array {
		$offset = ( $page - 1 ) * $perPage;
		$rows   = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE actor_user_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d',
				$this->table,
				$actorUserId,
				$perPage,
				$offset
			),
			ARRAY_A
		);
		return array_map( [ LearningEventDTO::class, 'fromArray' ], $rows ?: array() );
	}

	public function countByGroup( int $groupId ): int {
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE group_id = %d',
				$this->table,
				$groupId
			)
		);
	}

	/** Журнал неизменяем. */
	public function update(): never {
		throw new \LogicException( 'LearningEventRepository: записи ленты не изменяются.' );
	}
}
