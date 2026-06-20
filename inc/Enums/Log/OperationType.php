<?php

declare( strict_types=1 );

namespace Inc\Enums\Log;

/**
 * Тип CRUD-операции над сущностью (лог 1 — EntityAudit).
 */
enum OperationType: string {

	case Create = 'create';
	case Update = 'update';
	case Delete = 'delete';
	case Import = 'import';

	public function label(): string {
		return match ( $this ) {
			self::Create => 'Создание',
			self::Update => 'Изменение',
			self::Delete => 'Удаление',
			self::Import => 'Импорт',
		};
	}

	/** Цвет badge для UI (класс CSS). */
	public function badgeClass(): string {
		return match ( $this ) {
			self::Create => 'badge-success',
			self::Update => 'badge-warning',
			self::Delete => 'badge-danger',
			self::Import => 'badge-success',
		};
	}
}
