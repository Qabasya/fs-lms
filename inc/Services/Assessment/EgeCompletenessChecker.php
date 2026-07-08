<?php

declare( strict_types=1 );

namespace Inc\Services\Assessment;

use Inc\DTO\Assessment\AssessmentDTO;
use Inc\DTO\Assessment\EgeCompletenessResult;
use Inc\Services\Subject\PostTypeResolver;

/**
 * Class EgeCompletenessChecker
 *
 * Проверка укомплектованности ЕГЭ-работы (T7.15 / T16.6).
 * Сравнивает номера заданий, охваченных работой, с термами {key}_task_number таксономии.
 *
 * Два слоя:
 *  - мягкий ({@see getMissingTaskNumbers()}/{@see isComplete()}) — только пропуски,
 *    используется навигатором КЕГЭ (не блокирует);
 *  - строгий ({@see validate()}) — биекция задание↔номер 1:1 (D16.2): пропуски,
 *    дубли и «сироты» без номера; блокирует публикацию/старт (D16.3).
 *
 * @package Inc\Services\Assessment
 */
class EgeCompletenessChecker {

	/**
	 * Строгий вердикт укомплектованности (D16.2): ровно одно задание на каждый
	 * терм `{key}_task_number`, все номера покрыты, без дублей и заданий без номера.
	 *
	 * @param AssessmentDTO $assessment ЕГЭ-работа с набором taskIds.
	 * @param string        $subjectKey Ключ предмета.
	 */
	public function validate( AssessmentDTO $assessment, string $subjectKey ): EgeCompletenessResult {
		$taxonomy = $subjectKey . PostTypeResolver::TASK_NUMBER_SUFFIX;
		$terms    = get_terms( array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'fields'     => 'all',
		) );
		$terms = ( is_wp_error( $terms ) || ! is_array( $terms ) ) ? array() : $terms;

		// slug => name для номеров таксономии (эталонный набор).
		$termNames = array();
		foreach ( $terms as $term ) {
			$termNames[ $term->slug ] = $term->name;
		}

		// slug => сколько заданий его покрывают; список сирот (без валидного номера).
		$coverage = array();
		$orphans  = array();
		foreach ( $assessment->taskIds as $taskId ) {
			$taskId    = (int) $taskId;
			$taskTerms = wp_get_post_terms( $taskId, $taxonomy, array( 'fields' => 'slugs' ) );
			$slugs     = is_wp_error( $taskTerms ) ? array() : array_values( array_filter(
				(array) $taskTerms,
				static fn( $slug ) => isset( $termNames[ $slug ] )
			) );

			if ( empty( $slugs ) ) {
				$orphans[] = $taskId;
				continue;
			}
			foreach ( $slugs as $slug ) {
				$coverage[ $slug ] = ( $coverage[ $slug ] ?? 0 ) + 1;
			}
		}

		$missing    = array();
		$duplicated = array();
		foreach ( $termNames as $slug => $name ) {
			$count = $coverage[ $slug ] ?? 0;
			if ( 0 === $count ) {
				$missing[] = $name;
			} elseif ( $count > 1 ) {
				$duplicated[] = $name;
			}
		}

		usort( $missing, static fn( string $a, string $b ) => (int) $a - (int) $b );
		usort( $duplicated, static fn( string $a, string $b ) => (int) $a - (int) $b );

		return new EgeCompletenessResult(
			missing      : $missing,
			duplicated   : $duplicated,
			orphans      : $orphans,
			expectedCount: count( $termNames ),
			actualCount  : count( $assessment->taskIds ),
		);
	}

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
