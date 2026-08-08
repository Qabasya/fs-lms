<?php

declare( strict_types=1 );

namespace Inc\Controllers\System;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Services\Subject\PostTypeResolver;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class AdminFooterModalsController
 *
 * Рендер общих модалок Confirm/Alert в admin_footer на всех экранах, где
 * работает наш админ-JS (меню-страницы плагина + наши CPT).
 *
 * Выделен из Core\Enqueue (Т14.4): это рендер разметки, а не ассеты.
 *
 * @package Inc\Controllers\System
 */
class AdminFooterModalsController extends BaseController implements ServiceInterface {

	use Sanitizer;

	public function register(): void {
		// 'admin_footer' — хук для вывода HTML в подвале админки
		add_action( 'admin_footer', array( $this, 'renderConfirmModal' ) );
	}

	public function renderConfirmModal(): void {
		// Рендерим модалки везде, где работает наш админ-JS (меню-страницы + наши CPT).
		if ( ! $this->isPluginAdminScreen() ) {
			return;
		}

		// Модальное окно подтверждения действия (Confirm)
		$modal_path = $this->path( 'templates/admin/components/modals/confirm-modal.php' );

		if ( file_exists( $modal_path ) ) {
			require_once $modal_path;
		}

		// Модальное окно оповещения (Alert)
		$alert_modal_path = $this->path( 'templates/admin/components/modals/alert-modal.php' );

		if ( file_exists( $alert_modal_path ) ) {
			require $alert_modal_path;
		}
	}

	/**
	 * Плагинный экран админки: меню-страница (fs_/student_) или один из наших CPT.
	 * Должно совпадать с условием подключения ассетов в AdminAssets::enqueue(),
	 * иначе модалки Confirm/Alert не отрисуются там, где их JS уже работает
	 * (баг: модалка удаления шага не открывалась на экране правки урока).
	 */
	private function isPluginAdminScreen(): bool {
		$page = $this->sanitizeText( 'page', 'GET' );
		if ( str_starts_with( $page, 'fs_' ) || str_starts_with( $page, 'student_' ) ) {
			return true;
		}

		$screen = get_current_screen();
		if ( ! $screen ) {
			return false;
		}

		$pt = $screen->post_type;

		return PostTypeResolver::isTaskPostType( $pt )
			|| PostTypeResolver::isLessonPostType( $pt )
			|| PostTypeResolver::isWorkPostType( $pt )
			|| PostTypeResolver::isAssessmentPostType( $pt )
			|| PostTypeResolver::isCoursePostType( $pt )
			|| PostTypeResolver::isArticlePostType( $pt )
			|| PostTypeResolver::problems() === $pt;
	}
}
