<?php

declare( strict_types=1 );

namespace Inc\DTO\Task;

/**
 * Результат проверки одной строки CSV массовой правки: что изменится и почему нельзя.
 *
 * Не readonly: вторым проходом (коллизии номеров и файлов в рамках партии) строка может
 * получить ошибку уже после первичной сборки.
 */
final class TaskBulkPlanItemDTO {

	/**
	 * @param int                        $line        Строка файла
	 * @param int                        $postId      ID задания; 0 — не найдено
	 * @param string                     $oldSlug     Текущий номер
	 * @param string                     $newSlug     Номер после правки (равен старому, если не меняется)
	 * @param string|null                $newTitle    Новый заголовок целиком; null — не меняется
	 * @param string|null                $newDate     Новая дата «Y-m-d H:i:s»; null — не меняется
	 * @param int|null                   $numberTerm  ID терма номера задания, если серия меняется
	 * @param array<string, int[]>       $termIds     Таксономия → итоговые ID термов (только изменяемые)
	 * @param array<string, string[]>    $newTerms    Таксономия → названия термов, которых ещё нет (создаются)
	 * @param array<int, array<string, mixed>> $fileOps Операции с файлами ({@see \Inc\Services\Task\TaskBulkFileService::plan()})
	 * @param string[]                   $changes     Человекочитаемый список изменений
	 * @param string[]                   $warnings    Предупреждения (правка возможна)
	 * @param string[]                   $errors      Ошибки (строка не применяется)
	 */
	public function __construct(
		public int $line,
		public int $postId,
		public string $oldSlug,
		public string $newSlug,
		public ?string $newTitle = null,
		public ?string $newDate = null,
		public ?int $numberTerm = null,
		public array $termIds = array(),
		public array $newTerms = array(),
		public array $fileOps = array(),
		public array $changes = array(),
		public array $warnings = array(),
		public array $errors = array(),
	) {}

	public function fail( string $message ): void {
		$this->errors[] = $message;
	}

	public function hasErrors(): bool {
		return array() !== $this->errors;
	}

	public function slugChanges(): bool {
		return $this->newSlug !== $this->oldSlug;
	}

	/** Есть ли что применять. */
	public function isChange(): bool {
		return array() !== $this->changes;
	}

	/** Применяется ли строка: без ошибок и с изменениями. */
	public function isApplicable(): bool {
		return ! $this->hasErrors() && $this->isChange();
	}

	/** @return array{line: int, post_id: int, old: string, new: string, status: string, changes: string[], warnings: string[], errors: string[]} */
	public function toReport(): array {
		return array(
			'line'     => $this->line,
			'post_id'  => $this->postId,
			'old'      => $this->oldSlug,
			'new'      => $this->newSlug,
			'status'   => $this->hasErrors() ? 'error' : ( $this->isChange() ? 'ok' : 'skip' ),
			'changes'  => $this->changes,
			'warnings' => $this->warnings,
			'errors'   => $this->errors,
		);
	}
}
