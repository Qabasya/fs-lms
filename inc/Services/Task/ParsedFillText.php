<?php

declare( strict_types=1 );

namespace Inc\Services\Task;

/**
 * Class ParsedFillText
 *
 * Результат разбора текста с пропусками.
 *
 * @package Inc\Services\Task
 */
readonly class ParsedFillText {

	/**
	 * @param array<int, array{type: 'text'|'gap', content?: string, index?: int}> $segments
	 *   Сегменты текста: чередование текстовых кусков и мест пропусков.
	 * @param array<int, string[]> $gaps
	 *   Список допустимых ответов для каждого пропуска (indexed by gap index).
	 */
	public function __construct(
		public array $segments,
		public array $gaps,
	) {}

	public function gapCount(): int {
		return count( $this->gaps );
	}
}
