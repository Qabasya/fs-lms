<?php

declare( strict_types=1 );

namespace Inc\Contracts;

use Inc\DTO\Import\ImportContextDTO;
use Inc\DTO\Import\ImportRowResultDTO;

/**
 * Контракт импортёра одной строки CSV.
 *
 * Реализации (архивный/enrolled) сами управляют границей транзакции
 * своих записей (через {@see \Inc\Shared\Traits\TransactionRunner}) —
 * {@see \Inc\Services\Import\ImportService::run()} не оборачивает вызов
 * import() извне, так как enrolled-режим вызывает wp_insert_user()
 * (не поддерживает откат, должен выполняться вне транзакции).
 */
interface RowImporterInterface {

	/**
	 * Обязательные колонки CSV для этого импортёра (валидация заголовков файла).
	 *
	 * @return string[]
	 */
	public function requiredHeaders(): array;

	/**
	 * Импортирует одну строку.
	 *
	 * @param array<string, string> $row Ассоц-массив «заголовок → значение»
	 * @param ImportContextDTO       $ctx Контекст запуска
	 *
	 * @throws \InvalidArgumentException|\DomainException При ошибке строки — попадает в отчёт, не валит файл
	 */
	public function import( array $row, ImportContextDTO $ctx ): ImportRowResultDTO;
}
