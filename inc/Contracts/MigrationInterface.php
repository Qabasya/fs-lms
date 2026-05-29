<?php

declare(strict_types=1);

namespace Inc\Contracts;

interface MigrationInterface {
	/**
	 * Применяет миграцию — создаёт таблицы, добавляет колонки, индексы.
	 * * @return void
	 */
	public function up(): void;

	/**
	 * Откатывает миграцию — удаляет таблицы в обратном порядке.
	 * * @return void
	 */
	public function down(): void;

	/**
	 * Возвращает строку версии миграции в формате semver (например, '1.0.0').
	 * * @return string
	 */
	public function version(): string;

}