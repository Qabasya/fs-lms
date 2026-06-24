<?php

declare( strict_types=1 );

namespace Inc\Services\Assessment;

use Inc\DTO\Assessment\AssessmentDTO;
use Inc\Services\Subject\PostTypeResolver;

/**
 * Class EgeCompletenessChecker
 *
 * Мягкая проверка полноты ЕГЭ-работы (T7.15).
 * Сравнивает номера заданий, охваченных работой, с термами {key}_task_number таксономии.
 * Предупреждение — не блок: сохранение работы не блокируется при неполном покрытии.
 *
 * @package Inc\Services\Assessment
 */
class EgeCompletenessChecker {

	/**
	 * Возвращает отсутствующие номера заданий (термы таксономии, не покрытые работой).
	 *
	 * @param AssessmentDTO $assessment ЕГЭ-работа с набором taskIds.
	 * @param string        $subjectKey Ключ предмета.
	 * @return string[] Список меток отсутствующих термов (например, ['13', '14', '15']).
	 */
	public function getMissingTaskNumbers( AssessmentDTO $assessment, string $subjectKey ): array {
		$taxonomy = $subjectKey . PostTypeResolver::TASK_NUMBER_SUFFIX;
		$terms    = get_terms( [
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'fields'     => 'all',
		] );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return [];
		}

		// Собираем номера заданий, которые встречаются в задачах работы.
		$coveredNumbers = [];
		foreach ( $assessment->taskIds as $taskId ) {
			$taskTerms = wp_get_post_terms( $taskId, $taxonomy, [ 'fields' => 'slugs' ] );
			if ( ! is_wp_error( $taskTerms ) ) {
				foreach ( $taskTerms as $slug ) {
					$coveredNumbers[ $slug ] = true;
				}
			}
		}

		$missing = [];
		foreach ( $terms as $term ) {
			if ( ! isset( $coveredNumbers[ $term->slug ] ) ) {
				$missing[] = $term->name;
			}
		}

		// Числовая сортировка для корректного порядка номеров.
		usort( $missing, static fn( string $a, string $b ) => (int) $a - (int) $b );

		return $missing;
	}

	/** Удобная обёртка: работа полностью покрывает все номера? */
	public function isComplete( AssessmentDTO $assessment, string $subjectKey ): bool {
		return empty( $this->getMissingTaskNumbers( $assessment, $subjectKey ) );
	}
}
