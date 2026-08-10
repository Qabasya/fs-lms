<?php

declare( strict_types=1 );

namespace Inc\Services\Subject;

use Inc\Managers\Wp\TermManager;
use Inc\Services\Shared\Pluralizer;

/**
 * Class FilterGroupService
 *
 * Группы фильтров-таксономий для сайдбара публичных страниц предмета.
 *
 * Единый источник формата: и «Все задания», и каталог учебника рисуют один и
 * тот же сайдбар (`filter-sec` + `filter-option`) и обслуживаются одним
 * JS-компонентом (`FilterSection`), поэтому опции, сводка и счётчики строятся
 * здесь, а не в каждом строителе страницы отдельно.
 *
 * Различие страниц — только в источнике счётчиков: тренажёр считает фасеты
 * запросами к серверу, учебник — весь банк статей разом и пересчитывает срез
 * на клиенте.
 *
 * @package Inc\Services\Subject
 */
readonly class FilterGroupService {

	public function __construct(
		private TermManager $term_manager,
	) {}

	/**
	 * Опции одной группы: термины таксономии со счётчиками текущего среза.
	 *
	 * Счётчик берём не из `$term->count` (он суммирует все типы записей
	 * таксономии), а из переданного подсчёта строго по нужному CPT.
	 *
	 * @param string $taxonomy           Слаг таксономии.
	 * @param array  $counts             Счётчики [term_id => count] в текущем срезе.
	 * @param array  $selected_slugs     Слаги выбранных терминов.
	 * @param bool   $prefer_description Показывать описание термина вместо названия.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function options( string $taxonomy, array $counts, array $selected_slugs = array(), bool $prefer_description = false ): array {
		return array_map(
			function ( \WP_Term $term ) use ( $taxonomy, $counts, $selected_slugs, $prefer_description ): array {
				$count    = (int) ( $counts[ (int) $term->term_id ] ?? 0 );
				$selected = in_array( $term->slug, $selected_slugs, true );

				return array(
					'slug'     => $term->slug,
					'name'     => $prefer_description && '' !== trim( (string) $term->description )
						? trim( (string) $term->description )
						: $term->name,
					'count'    => $count,
					'url'      => $this->term_manager->getLink( $term->term_id, $taxonomy ),
					'selected' => $selected,
					// Выбранный термин остаётся видимым даже при нулевом счётчике —
					// иначе снять несовместимый выбор было бы нечем.
					'available' => $count > 0 || $selected,
				);
			},
			$this->term_manager->getAll( $taxonomy )
		);
	}

	/**
	 * Собирает группу фильтров вместе со сводкой и числом выбранных опций.
	 *
	 * @param string $taxonomy Слаг таксономии.
	 * @param string $name     Заголовок группы.
	 * @param array  $terms    Опции группы (см. options()).
	 * @param bool   $is_type  Группа типа задания (для склонения в сводке).
	 *
	 * @return array<string, mixed>
	 */
	public function group( string $taxonomy, string $name, array $terms, bool $is_type ): array {
		$available = array_values( array_filter( $terms, static fn( array $term ) => ! empty( $term['available'] ) ) );

		return array(
			'taxonomy'  => $taxonomy,
			'name'      => $name,
			'terms'     => $terms,
			'is_type'   => $is_type,
			'summary'   => $this->summary( $available, $is_type ),
			// Сколько опций пришло выбранными из URL — секция раскрывается, появляется бейдж.
			'active'    => count( array_filter( $terms, static fn( array $term ) => ! empty( $term['selected'] ) ) ),
			// Ноль доступных опций — группу скрываем целиком (термины остаются
			// в разметке, чтобы JS вернул их при снятии фильтров).
			'available' => count( $available ),
		);
	}

	/**
	 * Текст-сводка группы фильтров (виден, когда секция свёрнута и ничего не
	 * выбрано). Год (все термины — 4-значные числа) → см. yearSummary();
	 * тип → «Все N типов»; прочее → «Все N».
	 *
	 * JS-зеркало (пересчёт среза на клиенте) — `summaryFor()` в
	 * `src/js/frontend/components/article-catalog.js`.
	 *
	 * @param array $terms   Доступные опции группы.
	 * @param bool  $is_type Группа типа задания (для склонения «тип/типа/типов»).
	 *
	 * @return string
	 */
	private function summary( array $terms, bool $is_type ): string {
		$count = count( $terms );

		$years = array_filter( $terms, static fn( $t ) => (bool) preg_match( '/^\d{4}$/', (string) $t['name'] ) );
		if ( $count > 0 && count( $years ) === $count ) {
			return $this->yearSummary( array_map( static fn( $t ) => (int) $t['name'], $terms ) );
		}

		if ( $is_type ) {
			return sprintf( 'Все %d %s', $count, Pluralizer::ru( $count, 'тип', 'типа', 'типов' ) );
		}

		return sprintf( 'Все %d', $count );
	}

	/**
	 * Сводка группы годов. Диапазон «min—max» годится, только пока годы идут
	 * подряд: при отборе по типу задания в срезе остаются, например, 2021 и 2026,
	 * и прежний «2021—2026» выглядел так, будто подпись не отреагировала.
	 *
	 * Один год → «2021»; до трёх → перечисление; непрерывный ряд → «2021—2026»;
	 * остальное → «5 лет».
	 *
	 * @param int[] $years Годы доступных опций группы.
	 *
	 * @return string
	 */
	private function yearSummary( array $years ): string {
		$years = array_values( array_unique( $years ) );
		sort( $years );

		$count = count( $years );
		$min   = $years[0];
		$max   = $years[ $count - 1 ];

		if ( $count <= 3 ) {
			return implode( ', ', $years );
		}

		if ( $max - $min + 1 === $count ) {
			return "{$min}—{$max}";
		}

		return sprintf( '%d %s', $count, Pluralizer::ru( $count, 'год', 'года', 'лет' ) );
	}
}