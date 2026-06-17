<?php

declare( strict_types=1 );

namespace Inc\MetaBoxes\Fields;

use Inc\Services\PostTypeResolver;

/**
 * Class WorkRefField
 *
 * Упорядоченные ссылки на работы ({key}_works) внутри урока.
 *
 * @package Inc\MetaBoxes\Fields
 */
class WorkRefField extends RefSelectField {

	protected function refType(): string {
		return 'work';
	}

	protected function subjectFromPostType( string $post_type ): string {
		return PostTypeResolver::subjectFromLessonPostType( $post_type );
	}
}
