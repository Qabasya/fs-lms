<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Settings;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Access\UserRole;
use Inc\Enums\Wp\Nonce;
use Inc\Managers\Person\UserManager;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * AJAX-обработчики вкладки «Роли» в Настройках.
 *
 * Доступна только пользователям с Capability::ManageLmsRoles (исключительно administrator).
 * Защита: нельзя изменить роли administrator; нельзя снять с себя manage_lms_roles.
 */
class RolesSettingsCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	/** LMS-роли, доступные для назначения через эту форму. */
	private const ASSIGNABLE = array(
		UserRole::FSOffice->value,
		UserRole::FSMethodist->value,
		UserRole::FSMarket->value,
		UserRole::FSTeacher->value,
	);

	public function __construct(
		private readonly UserManager $userManager,
	) {
		parent::__construct();
	}

	/**
	 * Сохраняет набор LMS-ролей для указанного пользователя.
	 * Params: user_id (int), roles[] (array of role slugs).
	 */
	public function ajaxSaveUserRoles(): void {
		$this->authorize( Nonce::SaveRoles, Capability::ManageLmsRoles );

		$user_id = $this->requireInt( 'user_id' );
		$roles   = $_POST['roles'] ?? array();
		$roles   = is_array( $roles ) ? $roles : array();

		$target = get_userdata( $user_id );
		if ( ! $target ) {
			$this->error( 'Пользователь не найден.' );
			return;
		}

		// Защита: строка Administrator заблокирована для изменений.
		if ( in_array( 'administrator', (array) $target->roles, true ) ) {
			$this->error( 'Роли администратора изменить нельзя.' );
			return;
		}

		// Защита: нельзя снять manage_lms_roles с себя.
		// manage_lms_roles есть только у administrator — эта ветка теоретически не достижима,
		// но оставляем для явности.
		if ( $user_id === get_current_user_id() && ! in_array( 'administrator', (array) $target->roles, true ) ) {
			$this->error( 'Нельзя изменить собственные роли.' );
			return;
		}

		$new = array_intersect( $roles, self::ASSIGNABLE );

		foreach ( self::ASSIGNABLE as $slug ) {
			if ( in_array( $slug, $new, true ) ) {
				$this->userManager->addRole( $user_id, $slug );
			} else {
				$this->userManager->removeRole( $user_id, $slug );
			}
		}

		$this->success( array( 'user_id' => $user_id ) );
	}
}
