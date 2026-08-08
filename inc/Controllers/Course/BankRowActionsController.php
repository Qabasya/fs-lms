<?php

declare( strict_types=1 );

namespace Inc\Controllers\Course;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Services\Subject\PostTypeResolver;

/**
 * Class BankRowActionsController
 *
 * Admin-«довесок» банков контента, не связанный ни с меню, ни с фильтрами:
 * действие «Дублировать» в строке таблицы (контракт `data-clone-*` для
 * `admin/services/content-clone.js`) и модалка создания черновика в футере
 * страниц курсов/уроков/работ/контрольных.
 *
 * Выделен из LearningMenuController (Т14.1).
 *
 * @package Inc\Controllers\Course
 */
class BankRowActionsController extends BaseController implements ServiceInterface {

	public function register(): void {
		// «Дублировать» в строке таблицы банка — точка входа к AjaxHook::Clone*
		// (Эпик: допиливание UI недостижимых эндпоинтов).
		add_filter( 'post_row_actions', array( $this, 'addCloneRowAction' ), 10, 2 );

		// draft-creator-modal: рендерится на страницах уроков и курсов
		// (создание работы из урока / урока из курса без перезагрузки).
		add_action( 'admin_footer', array( $this, 'renderDraftCreatorModal' ) );
	}

	/**
	 * Добавляет действие «Дублировать» в строку таблицы банка контента.
	 *
	 * Кнопка ничего не делает сама: JS (`admin/services/content-clone.js`) читает
	 * `data-clone-*` и зовёт соответствующий AJAX-хук клонирования.
	 *
	 * @param array<string, string> $actions Действия строки
	 * @param \WP_Post              $post    Запись банка
	 *
	 * @return array<string, string>
	 */
	public function addCloneRowAction( array $actions, \WP_Post $post ): array {
		$type = match ( true ) {
			PostTypeResolver::isLessonPostType( $post->post_type )     => 'lesson',
			PostTypeResolver::isWorkPostType( $post->post_type )       => 'work',
			PostTypeResolver::isAssessmentPostType( $post->post_type ) => 'assessment',
			PostTypeResolver::isCoursePostType( $post->post_type )     => 'course',
			default                                                    => '',
		};

		if ( '' === $type || ! current_user_can( Capability::Admin->value ) ) {
			return $actions;
		}

		$actions['fs_lms_clone'] = sprintf(
			'<a href="#" class="js-fs-clone" data-clone-type="%s" data-clone-id="%d">%s</a>',
			esc_attr( $type ),
			$post->ID,
			esc_html__( 'Дублировать', 'fs-lms' )
		);

		return $actions;
	}

	/** Подключает модаль создания черновика на страницах курсов, уроков, работ. */
	public function renderDraftCreatorModal(): void {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}
		$pt = $screen->post_type;
		if ( PostTypeResolver::isWorkPostType( $pt )
			|| PostTypeResolver::isLessonPostType( $pt )
			|| PostTypeResolver::isCoursePostType( $pt )
			|| PostTypeResolver::isAssessmentPostType( $pt ) ) {
			include_once $this->plugin_path . 'templates/admin/components/modals/draft-creator-modal.php';
		}
	}
}
