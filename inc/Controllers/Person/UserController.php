<?php

declare( strict_types=1 );

namespace Inc\Controllers\Person;

use Inc\Core\BaseController;
use Inc\Contracts\ServiceInterface;
use Inc\Managers\Person\UserBehaviorManager;

/**
 * Class UserController
 *
 * Контроллер управления пользователями.
 *
 * @package Inc\Controllers
 * @implements ServiceInterface
 *
 * ### Основные обязанности:
 *
 * 1. **Ограничение доступа** — фильтрация доступа к админ-панели и медиафайлам.
 * 2. **Редирект после входа** — направление пользователей на правильную страницу после login.
 * 3. **Регистрация хуков** — подключение всех WordPress-хуков для управления пользователями.
 *
 * ### Архитектурная роль:
 *
 * Делегирует всю логику UserManager. Является точкой регистрации хуков пользовательской системы.
 */
class UserController extends BaseController implements ServiceInterface {

	public function __construct(
		private readonly UserBehaviorManager $user_behavior,
	) {
		parent::__construct();
	}

	public function register(): void {
		add_action( 'admin_init', array( $this->user_behavior, 'restrictAdminAccess' ) );
		add_action( 'profile_update', array( $this->user_behavior, 'clearEncryptedPasswordIfChanged' ), 10, 2 );

		add_filter( 'login_redirect', array( $this->user_behavior, 'resolveLoginRedirect' ), 10, 3 );

		add_filter( 'allow_password_reset', array( $this->user_behavior, 'blockPasswordReset' ), 10, 2 );

		add_filter( 'ajax_query_attachments_args', array( $this->user_behavior, 'getMediaFilterArgs' ) );

		add_filter(
			'request',
			function ( $query ) {
				if ( isset( $query['post_type'] ) && 'attachment' === $query['post_type'] ) {
					return $this->user_behavior->getMediaFilterArgs( $query );
				}
				return $query;
			}
		);
	}
}