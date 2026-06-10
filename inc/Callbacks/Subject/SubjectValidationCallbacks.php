<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Subject;

use Inc\Core\BaseController;
use Inc\Enums\PostMetaName;
use Inc\Services\PostTypeResolver;
use Inc\Services\Task\TaskPublishValidator;

/**
 * WP hook callbacks for task post validation.
 *
 * Hooks into wp_insert_post_data to block publication when required fields
 * or taxonomies are missing, and stores the error message in a transient
 * so admin_notices can display it after the redirect.
 */
class SubjectValidationCallbacks extends BaseController {

	public function __construct(
		private readonly TaskPublishValidator $validator,
	) {
		parent::__construct();
	}

	public function validateRequiredTaxonomies( array $data, array $postarr ): array {
		$postType = $data['post_type'] ?? '';

		if ( ! PostTypeResolver::isTaskPostType( $postType ) ) {
			return $data;
		}

		if ( ! in_array( $data['post_status'], array( 'publish', 'future' ), true ) ) {
			return $data;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return $data;
		}

		$error = $this->validator->validate(
			postType:   $postType,
			postMeta:   (array) ( $_POST[ PostMetaName::Meta->value ] ?? array() ),
			templateId: sanitize_key( $_POST[ PostMetaName::TemplateType->value ] ?? '' ),
			taxInput:   (array) ( $_POST['tax_input'] ?? array() ),
		);

		if ( null !== $error ) {
			$data['post_status'] = 'draft';
			set_transient( 'fs_lms_required_tax_error_' . get_current_user_id(), $error, 30 );
		}

		return $data;
	}
}
