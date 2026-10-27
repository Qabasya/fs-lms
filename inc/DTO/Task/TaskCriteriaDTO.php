<?php

declare( strict_types=1 );

namespace Inc\DTO\Task;

/**
 * Class TaskCriteriaDTO
 *
 * Критерии оценивания задания (мета `task_criteria.criteria`). Единственный разбор меты:
 * им пользуются результаты контрольной, детали работы и ручная проверка попытки.
 * Пустой набор — у задания один обычный балл.
 *
 * @package Inc\DTO\Task
 */
readonly class TaskCriteriaDTO {

	/** @param array<int, array<string, mixed>> $defs Определения критериев как в мете */
	private function __construct(
		private array $defs,
	) {}

	/** @param mixed $meta Мета задания (`PostMetaName::Meta`); не массив — критериев нет. */
	public static function fromMeta( mixed $meta ): self {
		$defs = is_array( $meta ) && is_array( $meta['task_criteria']['criteria'] ?? null )
			? $meta['task_criteria']['criteria']
			: array();

		return new self( $defs );
	}

	public function isEmpty(): bool {
		return array() === $this->defs;
	}

	/** @return array<int, array<string, mixed>> */
	public function defs(): array {
		return $this->defs;
	}

	/**
	 * Строки для интерфейса: подпись, максимум и начисленные баллы по индексу критерия.
	 *
	 * @param array<int, mixed>|null $awarded Баллы по критериям; null — ещё не оценено
	 *
	 * @return list<array{label: string, max_points: float, awarded: ?float}>
	 */
	public function rows( ?array $awarded ): array {
		$out = array();
		foreach ( $this->defs as $i => $def ) {
			$out[] = array(
				'label'      => (string) ( $def['label'] ?? '' ),
				'max_points' => (float) ( $def['max_points'] ?? 0 ),
				'awarded'    => isset( $awarded[ $i ] ) ? (float) $awarded[ $i ] : null,
			);
		}

		return $out;
	}
}
