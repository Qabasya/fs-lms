<?php

declare( strict_types=1 );

namespace Inc\MetaBoxes\Fields;

use Inc\Services\Subject\PostTypeResolver;

/**
 * Class AssessmentTaskRefField
 *
 * Упорядоченный список ссылок на задания ({key}_tasks / fs_lms_problems) внутри контрольной.
 *
 * @package Inc\MetaBoxes\Fields
 */
class AssessmentTaskRefField extends RefSelectField {

	protected function refType(): string {
		return 'item';
	}

	protected function subjectFromPostType( string $post_type ): string {
		return PostTypeResolver::subjectFromAssessmentPostType( $post_type );
	}

	protected function createProblemLabel(): string {
		return 'Создать задачу';
	}
}
