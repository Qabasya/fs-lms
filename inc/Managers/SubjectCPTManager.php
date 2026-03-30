<?php

namespace Inc\Managers;

class SubjectCPTManager {
	/**
	 * Регистрирует накопленные типы записей в WordPress.
	 * * @param array<string, array> $post_types Массив ['slug' => $args]
	 */
	public function register( array $post_types ): void {
		if ( empty( $post_types ) ) {
			return;
		}

		add_action( 'init', function () use ( $post_types ) {
			foreach ( $post_types as $slug => $args ) {
				register_post_type( $slug, $args );
			}
		} );
	}

}