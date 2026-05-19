<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Core\BaseController;
use Inc\Contracts\ServiceInterface;
use Inc\Enums\PageRoutes;
use Inc\Shared\Traits\TemplateRenderer;

class ProfileController extends BaseController implements ServiceInterface {

	use TemplateRenderer;

	public function __construct() {
		parent::__construct();
	}

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'handleRoutingAndPrivacy' ) );
		add_shortcode( 'fs_lms_profile', array( $this, 'renderProfileShortcode' ) );
	}

	public function handleRoutingAndPrivacy(): void {
		if ( is_user_logged_in() && PageRoutes::SIGN_IN->isCurrent() ) {
			wp_safe_redirect( PageRoutes::USER_PROFILE->url() );
			exit;
		}

		if ( ! is_user_logged_in() && PageRoutes::USER_PROFILE->isCurrent() ) {
			wp_safe_redirect( PageRoutes::SIGN_IN->url() );
			exit;
		}
	}

	public function renderProfileShortcode(): string {
		// 1. Проверяем авторизацию
		if ( ! is_user_logged_in() ) {
			return '<p>Доступ ограничен. Пожалуйста, авторизуйтесь.</p>';
		}

		$current_user = wp_get_current_user();

		// 2. Включаем буферизацию вывода, так как трейт возвращает void (делает require)
		ob_start();

		// Трейт выполнит require, и HTML запишется в буфер, а не уйдет на экран сразу
		$this->render(
			'frontend/profile',
			array(
				'user' => $current_user,
			)
		);

		// 3. Забираем накопленный HTML из буфера в виде чистой строки и закрываем буфер
		return ob_get_clean();
	}
}
