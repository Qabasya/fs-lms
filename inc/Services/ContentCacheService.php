<?php

declare(strict_types=1);

namespace Inc\Services;

/**
 * Class ContentCacheService
 * Управляет инвалидацией кеша динамического контента (заданий и статей).
 */
class ContentCacheService {
	/**
	 * Сбрасывает кеш таблицы "Последние задания/статьи".
	 *
	 * @param int      $post_id ID поста
	 * @param \WP_Post $post    Объект поста
	 */
	public function clearRecentContentCache(int $post_id, \WP_Post $post): void
	{
		if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
			return;
		}
		
		$this->purgeByPostType($post->post_type);
	}
	
	/**
	 * Сбрасывает кеш при удалении поста.
	 */
	public function clearCacheOnDelete(int $post_id): void
	{
		$post = get_post($post_id);
		if (!$post) {
			return;
		}
		
		$this->purgeByPostType($post->post_type);
	}
	
	/**
	 * Логика удаления транзиентов на основе типа поста.
	 */
	private function purgeByPostType(string $post_type): void
	{
		// Проверяем, что пост относится к системе (заканчивается на _tasks или _articles)
		if (!preg_match('/^(.+)_(tasks|articles)$/', $post_type, $matches)) {
			return;
		}
		
		$subject_key = $matches[1]; // Например, 'phys'
		$type_suffix = $matches[2]; // 'tasks' или 'articles'
		
		delete_transient("fs_lms_recent_{$type_suffix}_{$subject_key}");
	}
}