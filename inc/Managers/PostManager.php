<?php

declare(strict_types=1);

namespace Inc\Managers;

class PostManager
{
	public function getIds(string $post_type): array
	{
		return get_posts([
			'post_type'   => $post_type,
			'numberposts' => -1,
			'post_status' => 'any',
			'fields'      => 'ids',
		]);
	}

	public function getAll(string $post_type): array
	{
		return get_posts([
			'post_type'   => $post_type,
			'numberposts' => -1,
			'post_status' => 'any',
		]);
	}

	public function delete(int $post_id): void
	{
		wp_delete_post($post_id, true);
	}

	public function deleteAll(string $post_type): void
	{
		foreach ($this->getIds($post_type) as $id) {
			$this->delete((int) $id);
		}
	}

	public function insert(array $data): int
	{
		$id = wp_insert_post($data);
		return is_wp_error($id) ? 0 : (int) $id;
	}

	public function getAllMeta(int $post_id): array
	{
		$raw = get_post_meta($post_id);
		$result = [];
		foreach ($raw as $key => $_) {
			$result[$key] = get_post_meta($post_id, $key, true);
		}
		return $result;
	}

	public function updateMeta(int $post_id, string $key, mixed $value): void
	{
		update_post_meta($post_id, $key, $value);
	}
}