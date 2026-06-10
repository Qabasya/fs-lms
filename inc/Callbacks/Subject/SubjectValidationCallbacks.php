<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Subject;

use Inc\Core\BaseController;
use Inc\Enums\PostMetaName;
use Inc\Services\PostTypeResolver;
use Inc\Services\Task\TaskPublishValidator;

class SubjectValidationCallbacks extends BaseController {

	public function __construct(
		private readonly TaskPublishValidator $validator,
	) {
		parent::__construct();
	}

	/**
	 * Fires on wp_insert_post_data. Blocks publication if required fields/taxonomies are missing.
	 */
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

		$postMeta   = (array) ( $_POST[ PostMetaName::Meta->value ] ?? array() );
		$templateId = sanitize_key( $_POST[ PostMetaName::TemplateType->value ] ?? '' );
		$taxInput   = (array) ( $_POST['tax_input'] ?? array() );

		$error = $this->validator->getBlockingError( $postType, $taxInput )
			?? $this->validator->getSoftError( $postMeta, $templateId );

		if ( null !== $error ) {
			$back = wp_get_referer() ?: admin_url();
			wp_die(
				sprintf(
					'<p>%s</p><p><a href="%s">&larr; Назад</a></p>',
					esc_html( $error ),
					esc_url( $back )
				),
				esc_html__( 'Невозможно опубликовать задание', 'fs-lms' ),
				array( 'response' => 400 )
			);
		}

		return $data;
	}

	/**
	 * Fires on admin_notices on task CPT edit screens.
	 * Warns proactively when a required taxonomy has no terms yet.
	 */
	public function showEmptyRequiredTaxNotice(): void {
		$screen = get_current_screen();

		if ( ! $screen || ! PostTypeResolver::isTaskPostType( $screen->post_type ) ) {
			return;
		}

		$subjectKey = PostTypeResolver::subjectFromTaskPostType( $screen->post_type );
		$emptyTaxes = $this->validator->findEmptyRequired( $subjectKey );

		if ( empty( $emptyTaxes ) ) {
			return;
		}

		foreach ( $emptyTaxes as $tax ) {
			$canManage = current_user_can( 'manage_categories' );

			if ( $canManage ) {
				$link = sprintf(
					' <a href="%s">Добавить термы &rarr;</a>',
					esc_url( admin_url( 'edit-tags.php?taxonomy=' . $tax->slug ) )
				);
			} else {
				$link = ' Обратитесь к администратору.';
			}

			printf(
				'<div class="notice notice-warning"><p><strong>Внимание:</strong> Обязательная таксономия «%s» не содержит термов — задание нельзя будет опубликовать.%s</p></div>',
				esc_html( $tax->name ),
				$canManage ? $link : esc_html( $link )
			);
		}
	}
}
