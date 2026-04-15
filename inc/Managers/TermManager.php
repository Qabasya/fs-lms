<?php

declare(strict_types=1);

namespace Inc\Managers;

/**
 * Class TermManager
 *
 * Менеджер для работы с терминами таксономий WordPress.
 * Инкапсулирует базовые операции: получение, создание, удаление терминов,
 * а также привязку терминов к постам.
 *
 * @package Inc\Managers
 */
class TermManager
{
	/**
	 * Возвращает массив ID терминов указанной таксономии.
	 *
	 * @param string $taxonomy Слаг таксономии
	 *
	 * @return int[] Массив ID терминов
	 */
	public function getIds(string $taxonomy): array
	{
		$ids = get_terms([
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'fields'     => 'ids',
		]);

		return is_wp_error($ids) ? [] : (array) $ids;
	}

	/**
	 * Возвращает массив объектов терминов указанной таксономии.
	 *
	 * @param string $taxonomy Слаг таксономии
	 *
	 * @return \WP_Term[] Массив объектов терминов
	 */
	public function getAll(string $taxonomy): array
	{
		$terms = get_terms([
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
		]);

		return is_wp_error($terms) ? [] : (array) $terms;
	}

	/**
	 * Удаляет термин по ID.
	 *
	 * @param int    $term_id  ID термина
	 * @param string $taxonomy Слаг таксономии
	 *
	 * @return void
	 */
	public function delete(int $term_id, string $taxonomy): void
	{
		wp_delete_term($term_id, $taxonomy);
	}

	/**
	 * Удаляет все термины указанной таксономии.
	 *
	 * @param string $taxonomy Слаг таксономии
	 *
	 * @return void
	 */
	public function deleteAll(string $taxonomy): void
	{
		foreach ($this->getIds($taxonomy) as $id) {
			$this->delete((int) $id, $taxonomy);
		}
	}

	/**
	 * Проверяет существование термина по названию.
	 *
	 * @param string $name     Название термина
	 * @param string $taxonomy Слаг таксономии
	 *
	 * @return bool true, если термин существует
	 */
	public function exists(string $name, string $taxonomy): bool
	{
		return (bool) term_exists($name, $taxonomy);
	}

	/**
	 * Регистрирует таксономию, если она ещё не существует.
	 *
	 * @param string $taxonomy Слаг таксономии
	 *
	 * @return void
	 */
	public function ensureTaxonomy(string $taxonomy): void
	{
		if (!taxonomy_exists($taxonomy)) {
			// Регистрируем с минимальными параметрами для возможности вставки терминов
			register_taxonomy($taxonomy, []);
		}
	}

	/**
	 * Создаёт термин, если его ещё нет.
	 *
	 * @param string $name     Название термина
	 * @param string $taxonomy Слаг таксономии
	 * @param array  $args     Дополнительные аргументы (slug, description, parent и т.д.)
	 *
	 * @return void
	 */
	public function insert(string $name, string $taxonomy, array $args = []): void
	{
		if (!$this->exists($name, $taxonomy)) {
			wp_insert_term($name, $taxonomy, $args);
		}
	}

	/**
	 * Возвращает массив слагов терминов, привязанных к посту.
	 *
	 * @param int    $post_id  ID поста
	 * @param string $taxonomy Слаг таксономии
	 *
	 * @return string[] Массив слагов терминов
	 */
	public function getPostSlugs(int $post_id, string $taxonomy): array
	{
		$slugs = wp_get_post_terms($post_id, $taxonomy, ['fields' => 'slugs']);

		return is_wp_error($slugs) ? [] : (array) $slugs;
	}

	/**
	 * Привязывает термины к посту.
	 *
	 * @param int      $post_id  ID поста
	 * @param string[] $slugs    Массив слагов терминов
	 * @param string   $taxonomy Слаг таксономии
	 *
	 * @return void
	 */
	public function setPostTerms(int $post_id, array $slugs, string $taxonomy): void
	{
		wp_set_post_terms($post_id, $slugs, $taxonomy);
	}
}