<?php

declare( strict_types=1 );

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\Enums\Nonce;
use Inc\Enums\PageRoutes;
use Inc\Managers\PostManager;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

class ConsentSettingsCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly PostManager $postManager,
	) {
		parent::__construct();
	}

	public function ajaxLookupConsentByHash(): void {
		$this->authorize( Nonce::Manager );

		$hash = sanitize_text_field( wp_unslash( $_POST['hash'] ?? '' ) );

		if ( empty( $hash ) ) {
			$this->error( 'Хеш не указан.' );
		}

		$page = $this->postManager->findByPath( PageRoutes::ConsentPage->value );
		if ( null === $page ) {
			$this->error( 'Страница согласия не найдена.' );
		}

		if ( hash( 'sha256', $page->post_content ) === $hash ) {
			$this->success( array(
				'found'   => true,
				'content' => wp_kses_post( $page->post_content ),
				'version' => 'Текущая версия',
				'date'    => wp_date( 'd.m.Y H:i', strtotime( $page->post_modified ) ),
			) );
		}

		$revisions = wp_get_post_revisions( $page->ID, array( 'order' => 'DESC' ) );
		foreach ( $revisions as $revision ) {
			if ( hash( 'sha256', $revision->post_content ) === $hash ) {
				$this->success( array(
					'found'   => true,
					'content' => wp_kses_post( $revision->post_content ),
					'version' => 'Редакция от ' . wp_date( 'd.m.Y H:i', strtotime( $revision->post_date ) ),
					'date'    => wp_date( 'd.m.Y H:i', strtotime( $revision->post_date ) ),
				) );
			}
		}

		$this->success( array( 'found' => false ) );
	}
}
