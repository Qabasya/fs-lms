<?php

declare( strict_types=1 );

namespace Inc\Services\Assessment;

use Inc\DTO\Assessment\AssessmentDTO;
use Inc\DTO\Assessment\EgeCompletenessResult;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Services\Exam\ExamFormatRegistry;
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
	 * WP filter: позиции работы, у которых заведомо нет терма `{key}_task_number`
	 * (напр. ОГЭ №13-16 — ручная проверка, номер только в `AssessmentDTO::$taskNumbers`,
	 * см. докблок `Inc\Modules\EgeComputer\Config\OgeCriteriaConfig`). Ядро о таких
	 * позициях не знает — модуль дополняет список этим фильтром:
	 *   apply_filters( self::EXTRA_POSITIONS_FILTER, [], $assessment, $subjectKey )
	 *
	 * @return string[] Метки позиций (напр. ['13', '14', '15', '16']).
	 */
	public const EXTRA_POSITIONS_FILTER = 'fs_lms_assessment_completeness_extra_positions';

	public function __construct( private readonly ExamFormatRegistry $formats ) {}

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

		// slug => name для номеров таксономии (эталонный набор) + обратная карта name => slug.
		$termNames  = array();
		$nameToSlug = array();
		foreach ( $terms as $term ) {
			$termNames[ $term->slug ] = $term->name;
			$nameToSlug[ $term->name ] = $term->slug;
		}

		// Позиции вне таксономии (ОГЭ №13-16 и т.п.) — синтетический slug,
		// покрытие сверяется по AssessmentDTO::$taskNumbers ниже (тот же fallback,
		// что и для банковских задач без терма).
		$extraPositions = (array) apply_filters( self::EXTRA_POSITIONS_FILTER, array(), $assessment, $subjectKey );
		foreach ( $extraPositions as $position ) {
			$position = trim( (string) $position );
			if ( '' === $position || isset( $nameToSlug[ $position ] ) ) {
				continue; // уже есть таксономический терм с этим именем — не дублируем.
			}
			$slug               = 'manual_' . $position;
			$termNames[ $slug ] = $position;
			$nameToSlug[ $position ] = $slug;
		}

		// Фильтруем по формату: только номера 1..N.
		$termNames = $this->expectedNames( $termNames, $assessment->kind );

		// Перестраиваем name => slug по отфильтрованному набору.
		$nameToSlug = array();
		foreach ( $termNames as $slug => $name ) {
			$nameToSlug[ $name ] = $slug;
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

			// У банковских (fs_lms_problems) задач нет таксономического терма — номер
			// берём из AssessmentDTO::$taskNumbers (снапшот, авто-вычисляемый
			// AssessmentManager::setItemIds() из собственной меты банковской задачи).
			if ( empty( $slugs ) ) {
				$number = $assessment->taskNumbers[ $taskId ] ?? '';
				if ( '' !== $number && isset( $nameToSlug[ $number ] ) ) {
					$slugs = array( $nameToSlug[ $number ] );
				}
			}

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

		// slug => name для фильтрации по формату.
		$termNames = [];
		foreach ( $terms as $term ) {
			$termNames[ $term->slug ] = $term->name;
		}

		// Фильтруем по формату: только номера 1..N.
		$termNames = $this->expectedNames( $termNames, $assessment->kind );

		// name => slug (для fallback банковских задач по task_numbers).
		$nameToSlug = [];
		foreach ( $termNames as $slug => $name ) {
			$nameToSlug[ $name ] = $slug;
		}

		// Собираем номера заданий, которые встречаются в задачах работы.
		$coveredNumbers = [];
		foreach ( $assessment->taskIds as $taskId ) {
			$taskId    = (int) $taskId;
			$taskTerms = wp_get_post_terms( $taskId, $taxonomy, [ 'fields' => 'slugs' ] );
			$hasTerm   = false;
			if ( ! is_wp_error( $taskTerms ) ) {
				foreach ( $taskTerms as $slug ) {
					$coveredNumbers[ $slug ] = true;
					$hasTerm                 = true;
				}
			}
			// Банковская задача без терма — по номеру из task_numbers.
			if ( ! $hasTerm ) {
				$number = $assessment->taskNumbers[ $taskId ] ?? '';
				if ( '' !== $number && isset( $nameToSlug[ $number ] ) ) {
					$coveredNumbers[ $nameToSlug[ $number ] ] = true;
				}
			}
		}

		$missing = [];
		foreach ( $termNames as $slug => $name ) {
			if ( ! isset( $coveredNumbers[ $slug ] ) ) {
				$missing[] = $name;
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

	/**
	 * Фильтрует термы по формату: оставляет только валидные номера в диапазоне [1..N].
	 * Если формат не зарегистрирован или не определён — возвращает вход без изменений.
	 *
	 * @param array<string, string> $termNames slug => name
	 * @param AssessmentKind        $kind
	 * @return array<string, string> Отфильтрованные имена
	 */
	private function expectedNames( array $termNames, AssessmentKind $kind ): array {
		$n = $this->formats->unitCount( $kind );
		if ( $n <= 0 ) {
			return $termNames;
		}

		$expected = array();
		foreach ( $termNames as $slug => $name ) {
			if ( ctype_digit( $name ) && (int) $name >= 1 && (int) $name <= $n ) {
				$expected[ $slug ] = $name;
			}
		}
		return $expected;
	}
}
