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

		// Свой хук для метабоксов - add_meta_boxes
		add_action( 'add_meta_boxes', function () use ( $metaboxes ) {
			foreach ( $metaboxes as $id => $config ) {
				add_meta_box(
					$id,
					$config['title'],
					$config['callback'],
					$config['post_types'], // Может быть строкой или массивом CPT
					$config['context'],    // normal, side, advanced
					$config['priority'],   // high, low, default
					$config['args']        // Доп. аргументы (например, объект шаблона)
				);
			}
		} );
	}

	/**
	 * Низкоуровневое сохранение мета-данных поста.
	 *
	 * @param int $post_id ID поста
	 * @param string $meta_key Ключ (у нас это fs_lms_meta)
	 * @param mixed $value Очищенные данные
	 */
	public function save_meta( int $post_id, string $meta_key, $value ): void {
		// Мы не вешаем это на хук здесь, так как контроллер сам решает,
		// КОГДА вызвать сохранение после всех проверок безопасности (nonce).
		update_post_meta( $post_id, $meta_key, $value );
	}
}