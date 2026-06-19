<?php

declare( strict_types=1 );

namespace Inc\Shared\Traits;

/**
 * Trait TidiesCoreMetaBoxes
 *
 * Прибирает экран редактирования банк-CPT: убирает коробки ядра «Атрибуты» и
 * «Изображение записи», уносит «Автор» в правый сайдбар. Вызывать на хуке
 * `add_meta_boxes` с приоритетом > 10 (после регистрации коробок ядром).
 *
 * @package Inc\Shared\Traits
 */
trait TidiesCoreMetaBoxes {

	protected function tidyCoreMetaBoxes( string $post_type ): void {
		remove_meta_box( 'pageparentdiv', $post_type, 'side' );  // «Атрибуты»
		remove_meta_box( 'postimagediv', $post_type, 'side' );   // «Изображение записи»

		// Свой id, а не повторный 'authordiv': после remove_meta_box ядро метит
		// 'authordiv' как false в core-приоритете, и повторный add_meta_box того же
		// id молча отменяется (дедуп в add_meta_box).
		remove_meta_box( 'authordiv', $post_type, 'normal' );
		add_meta_box( 'fs_lms_author_box', 'Автор', 'post_author_meta_box', $post_type, 'side' );
	}
}
