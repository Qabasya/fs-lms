<?php
/**
 * Candidate guards for explicitly self-paced courses.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Controllers\Course;

use Inc\Contracts\ServiceInterface;
use Inc\Services\Course\SelfPacedContentGuard;
use Inc\Services\Task\TaskPublishGuard;

/** Last-line guard for native editors and importers, before metadata reaches storage. */
class SelfPacedContentGuardController implements ServiceInterface {
	private const NOTICE_PREFIX = 'fs_lms_self_paced_content_';

	/** Receive storage and validation dependencies. */
	public function __construct(
		private readonly SelfPacedContentGuard $guard,
		private readonly TaskPublishGuard $notices,
		private readonly \Inc\Managers\Wp\TermManager $terms,
	) {}

	/** Register WordPress storage and publication guards. */
	public function register(): void {
		add_filter( 'add_post_metadata', array( $this, 'guardMetadata' ), 5, 4 );
		add_filter( 'update_post_metadata', array( $this, 'guardMetadata' ), 5, 4 );
		add_filter( 'delete_post_metadata', array( $this, 'guardDeletion' ), 5, 3 );
		add_filter( 'wp_insert_post_empty_content', array( $this, 'guardNativeTerms' ), 10, 2 );
		add_filter( 'wp_insert_post_data', array( $this, 'guardPublication' ), 99, 2 );
		add_filter( 'pre_update_option_' . \Inc\Enums\Settings\OptionName::Metaboxes->value, array( $this, 'guardAssignments' ), 10, 2 );
		add_action( 'rest_api_init', array( $this, 'registerRestGuards' ) );
		add_action( 'admin_notices', array( $this, 'renderNotice' ) );
	}

	/** Reject an invalid candidate without throwing through the native editor. */
	public function guardMetadata( mixed $check, mixed $id, mixed $key, mixed $value = null ): mixed {
		if ( null !== $check ) {
			return $check;
		}
		try {
			$this->guard->assertMetadata( (int) $id, (string) $key, $value );
		} catch ( \InvalidArgumentException $error ) {
			$this->notices->warn( self::NOTICE_PREFIX, $error->getMessage() );
			return false;
		}
		return $check;
	}

	/** Check the state after removal of metadata. */
	public function guardDeletion( mixed $check, mixed $id, mixed $key ): mixed {
		return $this->guardMetadata( $check, $id, $key, null );
	}

	/** Keep incompatible courses in draft. */
	public function guardPublication( array $data, array $postarr ): array {
		if ( in_array( $data['post_status'] ?? '', array( 'publish', 'future' ), true ) ) {
			try {
				$this->guard->assertPublication( (int) ( $postarr['ID'] ?? 0 ) );
			} catch ( \InvalidArgumentException $error ) {
				$this->notices->warn( self::NOTICE_PREFIX, $error->getMessage() );
				$data['post_status'] = 'draft';
			}
		}
		return $data;
	}

	/** Register before REST handles taxonomies, which happens after the post is saved. */
	public function registerRestGuards(): void {
		foreach ( $this->guard->taskPostTypes() as $postType ) {
			add_filter( 'rest_pre_insert_' . $postType, array( $this, 'guardRestTerms' ), 10, 2 );
		}
	}

	/** Validate REST taxonomy IDs before any update; preserve earlier REST errors. */
	public function guardRestTerms( mixed $prepared, mixed $request ): mixed {
		if ( is_wp_error( $prepared ) || ! is_object( $prepared ) ) {
			return $prepared;
		}
		$id = (int) $request->get_param( 'id' );
		if ( $id <= 0 ) {
			return $prepared;
		}
		try {
			foreach ( $this->terms->restTaskNumberFields( (string) $prepared->post_type ) as $field => $taxonomy ) {
				if ( $request->has_param( $field ) ) {
					$this->terms->assertPostTerms( $id, array_map( 'intval', (array) $request->get_param( $field ) ), $taxonomy );
				}
			}
		} catch ( \InvalidArgumentException $error ) {
			return new \WP_Error( 'fs_lms_self_paced_content', $error->getMessage(), array( 'status' => 400 ) );
		}
		return $prepared;
	}

	/** Refuse native editor taxonomy changes before any post or relationship is saved. */
	public function guardNativeTerms( bool $isEmpty, array $postarr ): bool {
		if ( $isEmpty || empty( $postarr['ID'] ) ) {
			return $isEmpty;
		}
		try {
			foreach ( (array) ( $postarr['tax_input'] ?? array() ) as $taxonomy => $termInput ) {
				if ( str_ends_with( (string) $taxonomy, '_task_number' ) ) {
					$candidate = is_array( $termInput ) ? $termInput : explode( ',', (string) $termInput );
					$this->terms->assertPostTerms( (int) $postarr['ID'], $candidate, (string) $taxonomy );
				}
			}
		} catch ( \InvalidArgumentException $error ) {
			$this->notices->warn( self::NOTICE_PREFIX, $error->getMessage() );
			return true;
		}
		return $isEmpty;
	}

	/** Reject only assignment maps that make a used task incompatible. */
	public function guardAssignments( mixed $value, mixed $old ): mixed {
		try {
			$this->guard->assertTemplateAssignments( is_array( $value ) ? $value : array() );
		} catch ( \InvalidArgumentException $error ) {
			$this->notices->warn( self::NOTICE_PREFIX, $error->getMessage() );
			return $old;
		}
		return $value;
	}

	/** Render the explanation after the editor redirects. */
	public function renderNotice(): void {
		$this->notices->renderDeferredError( self::NOTICE_PREFIX, 'Самостоятельный курс' );
	}
}
