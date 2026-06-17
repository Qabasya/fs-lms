<?php

declare( strict_types=1 );

namespace Inc\MetaBoxes\Fields;

use Inc\Services\PostTypeResolver;

/**
 * Class TaskRefField
 *
 * Упорядоченные ссылки на задания ({key}_tasks) внутри работы.
 *
 * @package Inc\MetaBoxes\Fields
 */
class TaskRefField extends RefSelectField {

	protected function refType(): string {
		return 'task';
	}

	protected function subjectFromPostType( string $post_type ): string {
		return PostTypeResolver::subjectFromWorkPostType( $post_type );
	}

	protected function createLabel(): string {
		return 'Создать задание';
	}
}
