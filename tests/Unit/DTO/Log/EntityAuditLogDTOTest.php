<?php

declare( strict_types=1 );

namespace Unit\DTO\Log;

use Inc\DTO\Log\EntityAuditLogDTO;
use Inc\Enums\Log\EntityType;
use PHPUnit\Framework\TestCase;

class EntityAuditLogDTOTest extends TestCase {

	/** @return array<string, mixed> */
	private function row( string $entityType ): array {
		return array(
			'id'            => 1,
			'actor_user_id' => 1,
			'actor_role'    => 'administrator',
			'operation'     => 'create',
			'entity_type'   => $entityType,
			'entity_id'     => 50,
			'old_label'     => 'Лицей №2',
			'actor_ip'      => '127.0.0.1',
			'created_at'    => '2026-10-04 17:43:03',
		);
	}

	public function test_known_entity_type_is_resolved(): void {
		$dto = EntityAuditLogDTO::fromArray( $this->row( 'task' ) );

		self::assertSame( EntityType::Task, $dto->entityType );
		self::assertSame( 'task', $dto->entityTypeRaw );
	}

	/**
	 * Запись, оставленная другой веткой или версией (например, `exam_source`), не должна
	 * ронять страницу журнала: тип остаётся сырой строкой.
	 */
	public function test_unknown_entity_type_does_not_throw(): void {
		$dto = EntityAuditLogDTO::fromArray( $this->row( 'exam_source' ) );

		self::assertNull( $dto->entityType );
		self::assertSame( 'exam_source', $dto->entityTypeRaw );
	}
}
