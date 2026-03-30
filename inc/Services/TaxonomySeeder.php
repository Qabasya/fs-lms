<?php

namespace Inc\Services;

/**
 * Class TaxonomySeeder
 *
 * Отвечает за наполнение таксономий данными (терминами).
 * Паттерн Seeder — когда мы «засеиваем» базу данных начальными данными.
 */
class TaxonomySeeder {

	/**
	 * Конструктор.
	 *
	 * На данный момент не требует зависимостей, но оставлен для будущего расширения.
	 *
	 */
	public function __construct() {
		// Пустой конструктор для возможного DI в будущем
	}

	/**
	 * Заполняет таксономию терминами с номерами заданий.
	 *
	 * Полностью очищает существующие термины в таксономии и создаёт новые
	 * с номерами от 1 до $count.
	 *
	 * @param string $taxonomy Слаг таксономии (например, "math_task_number")
	 * @param int $count Количество заданий (максимальный номер)
	 *
	 * @return void
	 */
	public function seedTaskNumbers( string $taxonomy, int $count ): void {
		// Получаем все существующие термины в таксономии
		$terms = get_terms( [
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'fields'     => 'ids' // Оптимизация: получаем только ID
		] );

		// Удаляем все существующие термины (полная очистка перед сидингом)
		foreach ( $terms as $term_id ) {
			wp_delete_term( $term_id, $taxonomy );
		}

		// Создаём термины для каждого задания от 1 до $count
		for ( $i = 1; $i <= $count; $i ++ ) {
			$this->ensureTerm( (string) $i, $taxonomy, [
				'description' => "Задание №{$i}",
				'slug'        => (string) $i
			] );
		}
	}


	/**
	 * Вспомогательный метод: проверяет существование термина и создаёт его, если нужно.
	 *
	 * Если таксономия ещё не зарегистрирована (например, при создании предмета),
	 * метод автоматически регистрирует её с минимальными параметрами.
	 *
	 * @param string $name Название термина (будет использовано как имя)
	 * @param string $taxonomy Слаг таксономии
	 * @param array $args Дополнительные аргументы для wp_insert_term()
	 *                         (description, slug, parent и т.д.)
	 *
	 * @return void
	 */
	private function ensureTerm( string $name, string $taxonomy, array $args = [] ): void {
		// Если таксономия ещё не зарегистрирована, регистрируем её временно
		if ( ! taxonomy_exists( $taxonomy ) ) {
			// Регистрируем с минимальными параметрами, чтобы WP разрешил вставку терминов
			register_taxonomy( $taxonomy, array() );
		}

		// Повторная проверка: если таксономия всё ещё не существует — выходим
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return;
		}

		// Создаём термин, только если он ещё не существует
		if ( ! term_exists( $name, $taxonomy ) ) {
			wp_insert_term( $name, $taxonomy, $args );
		}
	}

// ============== Пока не используется! ============== //
	/**

	 * Позволяет массово добавить любые другие термины (например, Темы или Авторов).
	 *
	 * @param string $taxonomy Слаб таксономии
	 * @param array<string> $terms Список названий терминов
	 */
	public function seedTerms( string $taxonomy, array $terms ): void {
		foreach ( $terms as $term_name ) {
			$this->ensureTerm( $term_name, $taxonomy );
		}
	}

	/**
	 * Полная очистка таксономии от всех терминов.
	 * ВРЕМЕННЫЙ МЕТОД для дебага
	 */
	public function clearTaxonomy( string $taxonomy ): void {
		// Получаем вообще все термины, даже пустые
		$terms = get_terms( [
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
		] );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return;
		}

		foreach ( $terms as $term ) {
			// Использование wp_delete_term напрямую в коде обходит AJAX-ошибки интерфейса
			wp_delete_term( $term->term_id, $taxonomy );
		}
	}
}