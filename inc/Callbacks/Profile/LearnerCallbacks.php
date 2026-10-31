<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Profile;

use Inc\Core\BaseController;
use Inc\Enums\Wp\Nonce;
use Inc\Enums\Access\UserRole;
use Inc\Services\Profile\HomeLayoutService;
use Inc\Services\Profile\LearnerService;
use Inc\Services\Profile\ProfileViewResolver;
use Inc\Shared\Traits\AjaxResponse;
use Inc\Shared\Traits\Sanitizer;

/**
 * AJAX профиля учащегося/родителя (Эпик 7).
 *
 * Без capability (у ученика/родителя нет LMS-прав) — доступ гейтится нонсом +
 * авторизацией на данные: ученик видит ТОЛЬКО себя, родитель — только своих детей
 * ({@see ProfileContext}). Клиентский `student_person_id` не доверяем: для ученика
 * игнорируется, для родителя проверяется против списка детей.
 *
 * @package Inc\Callbacks\Profile
 */
class LearnerCallbacks extends BaseController {

	use AjaxResponse;
	use Sanitizer;

	public function __construct(
		private readonly LearnerService      $service,
		private readonly ProfileViewResolver $resolver,
		private readonly HomeLayoutService   $layouts,
	) {
		parent::__construct();
	}

	public function ajaxGetLearnerProfile(): void {
		Nonce::LearnerProfile->verify();

		if ( ! is_user_logged_in() ) {
			$this->error( __( 'Требуется вход.', 'fs-lms' ) );
			return;
		}

		// Правило «родитель видит только своих детей» — в ProfileContext.
		$ctx      = $this->resolver->context( get_current_user_id() );
		$personId = $ctx->resolveSubjectPersonId( $this->sanitizeInt( 'student_person_id' ) );

		if ( ! $personId ) {
			$this->error( __( 'Профиль учащегося не найден.', 'fs-lms' ) );
			return;
		}

		// Раскладка главной — настройка смотрящего (родитель настраивает свою главную, а не ребёнка).
		$this->success( $this->service->build( $personId )->toArray() + array( 'home_layout' => $this->layouts->get( get_current_user_id(), $ctx->role ) ) );
	}

	/**
	 * «Кастомизация» главной: сохраняет порядок секций и набор плиток текущего пользователя. Без capability — как и чтение профиля,
	 * доступ по нонсу и входу; сохраняется только то, что прошло белый список ({@see HomeLayoutService}).
	 * Params: layout — JSON `{sections: string[], tiles: string[]|null}`.
	 */
	public function ajaxSaveHomeLayout(): void {
		Nonce::LearnerProfile->verify();

		if ( ! is_user_logged_in() ) {
			$this->error( __( 'Требуется вход.', 'fs-lms' ) );
			return;
		}

		$layout = json_decode( $this->sanitizeAnswerText( 'layout' ), true );
		if ( ! is_array( $layout ) ) {
			$this->error( __( 'Некорректная раскладка.', 'fs-lms' ) );
			return;
		}

		$ctx = $this->resolver->context( get_current_user_id() );
		if ( ! in_array( $ctx->role, array( UserRole::FSStudent, UserRole::FSParent, UserRole::FSTeacher, UserRole::FSOffice ), true ) ) {
			$this->error( __( 'Кабинет недоступен.', 'fs-lms' ) );
			return;
		}

		$view = $this->resolver->viewFor( $ctx->role );
		$built = null !== $view ? $view->build( $ctx ) : array();
		$menuKeys = null !== $view ? array_column( array_merge( $built['nav'] ?? array(), $built['examNav'] ?? array() ), 'key' ) : null;
		$this->success( array( 'layout' => $this->layouts->save( get_current_user_id(), $layout, $ctx->role, $menuKeys ) ) );
	}
}
