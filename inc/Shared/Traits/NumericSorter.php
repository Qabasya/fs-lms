<?php
/**
 * Трейт для сортировки не по строке, а по числу. Алгоритм работы такой:
 * WordPress формирует SQL-запрос
 * │
 * ▼
 * ORDER BY t.name ASC     ← это $orderby
 * │
 * ▼
 * Наш фильтр перехватывает
 * │
 * ▼
 * ORDER BY CAST(t.name AS UNSIGNED) ASC    ← модифицированный $orderby
 * │
 * ▼
 * SQL-запрос уходит в базу данных
 */

namespace Inc\Shared\Traits;

trait NumericSorter {
	/**
	 * Превращает сортировку по полю в числовую для указанного хука.
	 * * @param string $hook Хук WordPress (например, 'get_terms_orderby', 'posts_orderby')
	 *
	 * @param string $field Поле в SQL, по которому идёт сортировка (например, 't.name' или 'post_title')
	 * @param callable $condition Коллбек-проверка, нужно ли применять сортировку в данный момент
	 */
	protected function addNumericSort( string $hook, string $field, callable $condition ): void {
		/**
		 * $orderby — текущая строка сортировки в SQL (например, "t.name ASC")
		 * $query_args — аргументы запроса (какую таксономию запрашивают, какой тип записи и т.д.)
		 * use ( $field, $condition ) — «захватываем» переменные из внешней области видимости внутрь анонимной функции
		 */
		add_filter( $hook, function ( $orderby, $query_args ) use ( $field, $condition ) {

			// Если условие (например, проверка таксономии или типа записи) не выполнено — выходим
			if ( ! $condition( $query_args ) ) {
				return $orderby;
			}

			// Заменяем прямое упоминание поля на CAST этого поля
			// Например: "t.name" -> "CAST(t.name AS UNSIGNED)"
			// CAST(t.name AS UNSIGNED) — t.name воспринимается как беззнаковое целое число
			if ( str_contains( $orderby, $field ) ) {
				return str_replace( $field, "CAST($field AS UNSIGNED)", $orderby );
			}

			// Если в orderby нет поля (например, там пусто), принудительно ставим его первым
			return "CAST($field AS UNSIGNED)";

		}, 10, 2 );
	}

}