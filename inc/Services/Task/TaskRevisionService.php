<?php

declare( strict_types=1 );

namespace Inc\Services\Task;

use Inc\Enums\Wp\PostMetaName;
use Inc\Services\Subject\PostTypeResolver;

/**
 * Class TaskRevisionService
 *
 * История изменений заданий («как в постах»): штатные ревизии WordPress. Поля задания лежат не в `post_content`,
 * а в мете (`fs_lms_meta` — массив полей шаблона, `fs_lms_template_type`), поэтому для CPT заданий:
 *  - мета входит в ревизию (`wp_post_revision_meta_keys`) — откат возвращает условие, ответ и поля шаблона;
 *  - правка одной меты считается изменением (`wp_save_post_revision_post_has_changed`) — иначе редакция не создавалась бы,
 *    ведь заголовок и текст не меняются.
 * Откат не трогает номер задания (терм таксономии) и статус публикации. Экран ревизий WordPress сравнивает только
 * заголовок, а мета откатывается без показа различий.
 *
 * @package Inc\Services\Task
 */
class TaskRevisionService {

	/**
	 * Ключи меты, которые ревизия задания хранит и восстанавливает.
	 *
	 * @return string[]
	 */
	private static function metaKeys(): array {
		return array( PostMetaName::Meta->value, PostMetaName::TemplateType->value );
	}

	/**
	 * Фильтр `wp_post_revision_meta_keys`: добавляет мету задания только для CPT заданий.
	 *
	 * @param string[] $keys     Уже зарегистрированные ключи
	 * @param string   $postType Тип записи
	 *
	 * @return string[]
	 */
	public function revisionMetaKeys( array $keys, string $postType ): array {
		return PostTypeResolver::isTaskPostType( $postType ) ? array_merge( $keys, self::metaKeys() ) : $keys;
	}

	/**
	 * Фильтр `wp_save_post_revision_post_has_changed`: у задания изменение меты — изменение записи.
	 * Редакция без сохранённой меты (до включения истории) считается отличающейся.
	 */
	public function hasChanged( bool $changed, \WP_Post $lastRevision, \WP_Post $post ): bool {
		if ( $changed || ! PostTypeResolver::isTaskPostType( $post->post_type ) ) {
			return $changed;
		}

		foreach ( self::metaKeys() as $key ) {
			if ( get_post_meta( $lastRevision->ID, $key, true ) !== get_post_meta( $post->ID, $key, true ) ) {
				return true;
			}
		}

		return false;
	}
}
