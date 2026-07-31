<?php

declare(strict_types=1);

namespace Inc\Services\Subject;

use Inc\Enums\Wp\TransientKey;
use Inc\Managers\Wp\TransientManager;

/**
 * Class ContentCacheService
 *
 * Управляет инвалидацией кеша динамического контента (заданий и статей).
 *
 * @package Inc\Services
 *
 * ### Основные обязанности:
 *
 * 1. **Очистка кеша при сохранении** — сброс кеша таблиц "Последние задания/статьи" при обновлении поста.
 * 2. **Очистка кеша при удалении** — сброс кеша при удалении поста.
 * 3. **Очистка по типу поста** — определение ключа предмета и типа контента для удаления соответствующего транзиента.
 *
 * ### Архитектурная роль:
 *
 * Подключается к хукам 'save_post' и 'delete_post' для автоматической инвалидации кешированных данных.
 * Работает через {@see \Inc\Managers\Wp\TransientManager} (ключи — {@see \Inc\Enums\Wp\TransientKey}).
 */
class ContentCacheService {

	/**
	 * @param TransientManager $transients Кеш готовых HTML-таблиц банка
	 */
	public function __construct(
		private readonly TransientManager $transients,
	) {}

	
	/**
	 * Сбрасывает кеш таблицы "Последние задания/статьи" при сохранении поста.
	 *
	 * @param int      $post_id ID поста
	 * @param \WP_Post $post    Объект поста
	 *
	 * @return void
	 */
	public function clearRecentContentCache( int $post_id, \WP_Post $post ): void {
		// wp_is_post_revision() — проверяет, является ли пост ревизией (историей изменений)
		// wp_is_post_autosave() — проверяет, является ли пост автосохранением
		// Пропускаем ревизии и автосохранения, чтобы не сбрасывать кеш каждый раз
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		
		$this->purgeByPostType( $post->post_type );
	}
	
	/**
	 * Сбрасывает кеш при удалении поста.
	 *
	 * @param int $post_id ID поста
	 *
	 * @return void
	 */
	public function clearCacheOnDelete( int $post_id ): void {
		// get_post() — получает объект поста по ID
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}
		
		$this->purgeByPostType( $post->post_type );
	}
	
	/**
	 * Удаляет транзиенты на основе типа поста.
	 *
	 * @param string $post_type Тип поста (например, 'phys_tasks' или 'math_articles')
	 *
	 * @return void
	 */
	private function purgeByPostType( string $post_type ): void {
		// preg_match() с регулярным выражением для извлечения ключа предмета и суффикса
		// Пример: 'phys_tasks' → $matches[1] = 'phys', $matches[2] = 'tasks'
		if ( ! preg_match( '/^(.+)_(tasks|articles)$/', $post_type, $matches ) ) {
			return;
		}
		
		$subject_key = $matches[1];  // Ключ предмета (например, 'phys')
		$type_suffix = $matches[2];  // Тип контента: 'tasks' или 'articles'
		
		// Ключ общий с SubjectDataCallbacks — оба берут его из TransientKey
		$key = 'tasks' === $type_suffix ? TransientKey::RecentTasks : TransientKey::RecentArticles;
		$this->transients->delete( $key, $subject_key );
	}
}