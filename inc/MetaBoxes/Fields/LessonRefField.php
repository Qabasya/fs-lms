<?php

declare( strict_types=1 );

namespace Inc\MetaBoxes\Fields;

use Inc\Services\PostTypeResolver;

/**
 * Class LessonRefField
 *
 * Упорядоченные ссылки на уроки ({key}_lessons) внутри курса.
 *
 * @package Inc\MetaBoxes\Fields
 */
class LessonRefField extends RefSelectField {

	protected function refType(): string {
		return 'lesson';
	}

	protected function subjectFromPostType( string $post_type ): string {
		return PostTypeResolver::subjectFromCoursePostType( $post_type );
	}
}
