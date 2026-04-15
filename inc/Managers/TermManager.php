<?php

declare(strict_types=1);

namespace Inc\Managers;

class TermManager
{
	public function getIds(string $taxonomy): array
	{
		$ids = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false, 'fields' => 'ids']);
		return is_wp_error($ids) ? [] : (array) $ids;
	}

	public function getAll(string $taxonomy): array
	{
		$terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
		return is_wp_error($terms) ? [] : (array) $terms;
	}

	public function delete(int $term_id, string $taxonomy): void
	{
		wp_delete_term($term_id, $taxonomy);
	}

	public function deleteAll(string $taxonomy): void
	{
		foreach ($this->getIds($taxonomy) as $id) {
			$this->delete((int) $id, $taxonomy);
		}
	}

	public function exists(string $name, string $taxonomy): bool
	{
		return (bool) term_exists($name, $taxonomy);
	}

	public function ensureTaxonomy(string $taxonomy): void
	{
		if (!taxonomy_exists($taxonomy)) {
			register_taxonomy($taxonomy, []);
		}
	}

	public function insert(string $name, string $taxonomy, array $args = []): void
	{
		if (!$this->exists($name, $taxonomy)) {
			wp_insert_term($name, $taxonomy, $args);
		}
	}

	public function getPostSlugs(int $post_id, string $taxonomy): array
	{
		$slugs = wp_get_post_terms($post_id, $taxonomy, ['fields' => 'slugs']);
		return is_wp_error($slugs) ? [] : (array) $slugs;
	}

	public function setPostTerms(int $post_id, array $slugs, string $taxonomy): void
	{
		wp_set_post_terms($post_id, $slugs, $taxonomy);
	}
}