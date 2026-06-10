<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Services\ConsentService;
use RuntimeException;

/**
 * Контроллер публичной страницы просмотра согласия на обработку ПД.
 *
 * Маршрут: /lms/consent/{type}/{version}
 *   - type:    ключ согласия из ConsentDefinitionsRepository
 *   - version: sha256-хеш версии (или 'current' для текущей)
 */
class ConsentController extends BaseController implements ServiceInterface {

	public function __construct(
		private readonly ConsentService $consentService,
	) {
		parent::__construct();
	}

	public function register(): void {
		add_action( 'init',             array( $this, 'addRewriteRule' ) );
		add_filter( 'query_vars',       array( $this, 'addQueryVars' ) );
		add_filter( 'template_include', array( $this, 'loadConsentTemplate' ) );
	}

	public function addRewriteRule(): void {
		add_rewrite_rule(
			'^lms/consent/([^/]+)/([^/]+)/?$',
			'index.php?fs_consent_type=$matches[1]&fs_consent_version=$matches[2]',
			'top'
		);
	}

	public function addQueryVars( array $vars ): array {
		$vars[] = 'fs_consent_type';
		$vars[] = 'fs_consent_version';
		return $vars;
	}

	public function loadConsentTemplate( string $template ): string {
		$typeSlug = get_query_var( 'fs_consent_type' );
		$version  = get_query_var( 'fs_consent_version' );

		if ( empty( $typeSlug ) || empty( $version ) ) {
			return $template;
		}

		$page = $this->consentService->getPageForType( $typeSlug );
		if ( null === $page ) {
			return $this->serve404();
		}

		try {
			$text = $this->consentService->getDocumentText( $typeSlug, $version );
		} catch ( RuntimeException $e ) {
			return $this->serve404();
		}

		// Сообщаем WordPress, что это обычная страница — тема применит свои стили
		global $wp_query, $post;
		$wp_query->queried_object    = $page;
		$wp_query->queried_object_id = $page->ID;
		$wp_query->is_singular       = true;
		$wp_query->is_page           = true;
		$wp_query->is_404            = false;
		$wp_query->posts             = array( $page );
		$wp_query->post              = $page;
		$wp_query->found_posts       = 1;
		$post                        = $page; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $page );
		status_header( 200 );

		// Подменяем содержимое страницы нужной версией (текущей или архивной)
		add_filter( 'the_content', fn() => wp_kses_post( $text ), PHP_INT_MAX );

		// Возвращаем шаблон страницы из темы; fallback — плагиновый шаблон
		return get_page_template()
			?: locate_template( array( 'singular.php', 'index.php' ), false )
			?: $this->path( 'templates/frontend/consent-page.php' );
	}

	private function serve404(): string {
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
		return get_404_template();
	}
}
