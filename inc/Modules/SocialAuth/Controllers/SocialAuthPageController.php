<?php

declare( strict_types=1 );

namespace Inc\Modules\SocialAuth\Controllers;

use Inc\Modules\SocialAuth\Callbacks\SocialAuthCallbacks;
use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Enums\Wp\PageRoutes;
use Inc\Enums\Wp\ShortCode;
use Inc\Shared\Traits\TemplateRenderer;

class SocialAuthPageController extends BaseController implements ServiceInterface {

	use TemplateRenderer;

	public function __construct(
		private readonly SocialAuthCallbacks $auth_callbacks
	) {
		parent::__construct();
	}

	public function register(): void {
		add_shortcode( ShortCode::LoginForm->value, array( $this, 'renderLoginPage' ) );
		add_action( 'init', array( $this, 'redirectToCustomLogin' ) );
		// Приоритет 20: после AuthLogController (10), чтобы попытка успела залогироваться до redirect+exit.
		add_action( 'wp_login_failed', array( $this, 'redirectFailedLogin' ), 20, 1 );
		add_filter( 'template_include', array( $this, 'forceCleanAuthLayout' ), 9999 );
	}

	public function renderLoginPage(): string {
		if ( is_user_logged_in() ) {
			wp_safe_redirect( PageRoutes::UserProfile->url() );
			exit;
		}

		$providers = $this->auth_callbacks->getEnabledProviders();

		ob_start();
		$this->render(
			'frontend/auth-page',
			array(
				'providers'     => $providers,
				'lost_pass_url' => wp_lostpassword_url(),
			)
		);

		return (string) ob_get_clean();
	}

	public function redirectToCustomLogin(): void {
		global $pagenow;

		if ( 'wp-login.php' === $pagenow && ! isset( $_POST['wp-submit'] ) && 'GET' === $_SERVER['REQUEST_METHOD'] && 'logout' !== ( $_GET['action'] ?? '' ) ) {
			wp_safe_redirect( PageRoutes::SignIn->url() );
			exit;
		}
	}

	public function redirectFailedLogin( string $username ): void {
		if ( empty( $_POST['fs_lms_login'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		$url = add_query_arg(
			array(
				'login'   => 'failed',
				'fs_user' => rawurlencode( $username ),
			),
			PageRoutes::SignIn->url()
		);

		wp_safe_redirect( $url );
		exit;
	}

	public function forceCleanAuthLayout( string $template ): string {
		if ( is_admin() ) {
			return $template;
		}

		global $post;

		if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, ShortCode::LoginForm->value ) ) {
			$plugin_template = $this->path( 'templates/frontend/clean-page.php' );

			if ( file_exists( $plugin_template ) ) {
				return $plugin_template;
			}
		}

		return $template;
	}
}
