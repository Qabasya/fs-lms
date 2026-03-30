<?php

namespace Inc\Services;

/**
 * Class TaxonomySeeder
 *
 * Отвечает за наполнение таксономий данными (терминами).
 * Паттерн Seeder — когда мы «засеиваем» базу данных начальными данными.
 */
class TaxonomySeeder {

	public function __construct() {
	}

	public function seedTaskNumbers( string $taxonomy, int $count ): void {
		for ( $i = 1; $i <= $count; $i ++ ) {
			$this->ensureTerm( (string) $i, $taxonomy, [
				'description' => "Задание №{$i}",
				'slug'        => (string) $i
			] );
		}
	}


	/**
	 * Вспомогательный метод: проверяет существование термина и создает его, если нужно.
	 */
	private function ensureTerm( string $name, string $taxonomy, array $args = [] ): void {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			// Если таксономия еще не зарегистрирована (момент создания предмета),
			// регистрируем её с минимальными параметрами, чтобы WP разрешил вставку.
			register_taxonomy( $taxonomy, [] );
		}
		// Работаем только если таксономия уже зарегистрирована в системе
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return;
		}

		if ( ! term_exists( $name, $taxonomy ) ) {
			wp_insert_term( $name, $taxonomy, $args );
		}
	}


	/**
	 * Пока не используется!
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
}