<?php

declare( strict_types=1 );

namespace Inc\Services\Task;

use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Wp\PostManager;
use Inc\Services\Subject\PostTypeResolver;

/**
 * Class TaskSearchIndexer
 *
 * Поддерживает поисковый индекс заданий. Условие задания хранится в
 * сериализованном мета-поле fs_lms_meta и недоступно нативному поиску
 * WordPress (WP_Query s ищет только по заголовку и post_content).
 *
 * При каждом сохранении меты задания сервис зеркалит склеенный текст условия
 * (без HTML) в post_content поста — так нативный `s` на странице «Все задания»
 * находит задания и по заголовку, и по фрагменту условия, без дорогих LIKE
 * по сериализованной мете.
 *
 * @package Inc\Services\Task
 */
class TaskSearchIndexer {

	/** Защита от повторного входа: обновление post_content не должно рекурсивно триггерить переиндексацию. */
	private bool $reindexing = false;

	public function __construct(
		private readonly PostManager     $post_manager,
		private readonly TaskMetaService $task_meta_service,
	) {}

	/**
	 * Хук added_post_meta / updated_post_meta: переиндексирует задание при
	 * сохранении его меты. Реагирует только на ключ fs_lms_meta у CPT заданий.
	 *
	 * @param int    $meta_id  ID строки меты (не используется).
	 * @param int    $post_id  ID поста.
	 * @param string $meta_key Ключ меты.
	 *
	 * @return void
	 */
	public function onMetaSaved( int $meta_id, int $post_id, string $meta_key ): void {
		if ( PostMetaName::Meta->value !== $meta_key ) {
			return;
		}

		if ( ! PostTypeResolver::isTaskPostType( (string) get_post_type( $post_id ) ) ) {
			return;
		}

		$this->reindex( $post_id );
	}

	/**
	 * Пересобирает post_content задания из текста условия (plain text).
	 *
	 * @param int $post_id ID задания.
	 *
	 * @return void
	 */
	public function reindex( int $post_id ): void {
		if ( $this->reindexing ) {
			return;
		}

		$meta = $this->post_manager->getMeta( $post_id, PostMetaName::Meta->value );
		$meta = is_array( $meta ) ? $meta : array();

		$condition = $this->task_meta_service->getCombinedCondition( $meta );
		$plain     = trim( (string) wp_strip_all_tags( $condition ) );

		$this->reindexing = true;
		$this->post_manager->updatePostContent( $post_id, $plain );
		$this->reindexing = false;
	}
}