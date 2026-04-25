<?php
declare(strict_types=1);

namespace Inc\Shared\Traits;

/**
 * Trait TaxonomySeeder
 *
 * Предоставляет методы для начального заполнения (сидирования) таксономий терминами.
 *
 * @package Inc\Shared\Traits
 *
 * ### Основные обязанности:
 *
 * 1. **Генерация терминов** — создание терминов для таксономии номеров заданий (1, 2, 3...).
 * 2. **Очистка перед заполнением** — удаление существующих терминов перед созданием новых.
 * 3. **Гарантия существования** — проверка и регистрация таксономии при необходимости.
 *
 * ### Архитектурная роль:
 *
 * Используется в SubjectCrudCallbacks при создании нового предмета для автоматической
 * генерации таксономии номеров заданий. Обеспечивает консистентность начальных данных.
 */
trait TaxonomySeeder {
	
	/**
	 * Создаёт термины для таксономии номеров заданий.
	 *
	 * @param string $taxonomy Слаг таксономии (например, 'math_task_number')
	 * @param int    $count    Количество терминов для создания (номера заданий)
	 * @param string $prefix   Префикс для слага термина (например, 'math')
	 *
	 * @return void
	 */
	public function seedTaskNumbers( string $taxonomy, int $count, string $prefix ): void {
		// get_terms() — получаем все существующие ID терминов указанной таксономии
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,    // Включая пустые термины
				'fields'     => 'ids',    // Возвращаем только ID
			)
		);
		
		// Удаляем все существующие термины (полная перезапись)
		foreach ( $terms as $term_id ) {
			// wp_delete_term() — удаляет термин и все его связи
			wp_delete_term( $term_id, $taxonomy );
		}
		
		// Создаём новые термины от 1 до $count
		for ( $i = 1; $i <= $count; $i++ ) {
			$this->ensureSeederTerm(
				(string) $i,                                    // Название термина (номер)
				$taxonomy,
				array(
					'description' => "Задание №{$i}",           // Описание для отображения
					'slug'        => "{$prefix}_{$i}",          // Уникальный слаг (например, 'math_1')
				)
			);
		}
	}
	
	/**
	 * Гарантирует существование термина (создаёт, если нужно).
	 *
	 * @param string $name     Название термина
	 * @param string $taxonomy Слаг таксономии
	 * @param array  $args     Дополнительные аргументы (slug, description, parent)
	 *
	 * @return void
	 */
	private function ensureSeederTerm( string $name, string $taxonomy, array $args = array() ): void {
		// taxonomy_exists() — проверяет, зарегистрирована ли таксономия в WordPress
		if ( ! taxonomy_exists( $taxonomy ) ) {
			// register_taxonomy() — регистрирует таксономию (минимальная конфигурация)
			register_taxonomy( $taxonomy, array() );
		}
		
		// Повторная проверка на случай ошибки регистрации
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return;
		}
		
		// term_exists() — проверяет, существует ли термин с таким названием
		if ( ! term_exists( $name, $taxonomy ) ) {
			// wp_insert_term() — создаёт новый термин в базе данных
			wp_insert_term( $name, $taxonomy, $args );
		}
	}
}