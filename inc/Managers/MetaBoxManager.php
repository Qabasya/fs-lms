<?php

namespace Inc\Managers;

/**
 * Class MetaBoxManager
 *
 * Низкоуровневый менеджер для работы с метабоксами и мета-данными.
 * Инкапсулирует прямые вызовы WordPress API (add_meta_box, update_post_meta).
 */
class MetaBoxManager {

	public function register( array $metaboxes ): void {
		if ( empty( $metaboxes ) ) {
			return;
		}

		add_action( 'add_meta_boxes', function () use ( $metaboxes ) {
			foreach ( $metaboxes as $id => $config ) {
				$this->addSingleMetabox( $id, $config );
			}
		} );
	}

	/**
	 * Регистрирует один метабокс.
	 * Удобно использовать из контроллера при необходимости.
	 */
	public function addMetabox(
		string $id,
		string $title,
		callable $callback,
		string|array $post_types,
		string $context = 'normal',
		string $priority = 'default',
		array $args = []
	): void {
		add_action( 'add_meta_boxes', function () use ( $id, $title, $callback, $post_types, $context, $priority, $args ) {
			$this->addSingleMetabox( $id, [
				'title'      => $title,
				'callback'   => $callback,
				'post_types' => $post_types,
				'context'    => $context,
				'priority'   => $priority,
				'args'       => $args,
			] );
		} );
	}

	/**
	 * Внутренний метод для добавления одного метабокса.
	 */
	private function addSingleMetabox( string $id, array $config ): void {
		$defaults = [
			'title'      => 'Untitled Metabox',
			'callback'   => '__return_null',
			'post_types' => [],
			'context'    => 'normal',
			'priority'   => 'default',
			'args'       => [],
		];

		$config = wp_parse_args( $config, $defaults );

		// Нормализуем post_types в массив
		$post_types = (array) $config['post_types'];

		foreach ( $post_types as $post_type ) {
			if ( empty( $post_type ) || ! is_string( $post_type ) ) {
				continue;
			}

			add_meta_box(
				$id,
				$config['title'],
				$config['callback'],
				$post_type,
				$config['context'],
				$config['priority'],
				$config['args']
			);
		}
	}

	/**
	 * Сохраняет мета-данные поста после всех проверок безопасности.
	 *
	 * @param int $post_id ID поста
	 * @param string $meta_key Ключ мета-поля
	 * @param mixed $value Значение (уже очищенное)
	 */
	public function saveMeta( int $post_id, string $meta_key, $value ): void {
		if ( empty( $post_id ) || empty( $meta_key ) ) {
			return;
		}

		// Дополнительная защита: проверяем, что пост существует и не является ревизией
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		update_post_meta( $post_id, $meta_key, $value );
	}

	/**
	 * Удаляет мета-данные поста.
	 */
	public function deleteMeta( int $post_id, string $meta_key ): void {
		if ( empty( $post_id ) || empty( $meta_key ) ) {
			return;
		}

		delete_post_meta( $post_id, $meta_key );
	}
}