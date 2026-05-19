<?php

declare( strict_types=1 );

namespace Inc\Core;

use Inc\Managers\UserManager;
use Inc\Services\PageGeneratorService;
use Inc\Enums\PageRoutes;

class Activate {

	public static function activate(): void {
		$container = new Container();

		/** @var UserManager $user_manager */
		$user_manager = $container->get( UserManager::class );
		$user_manager->createRoles();

		self::generatePages();

		flush_rewrite_rules();
	}

	private static function generatePages(): void {
		$generator = new PageGeneratorService();

		$generator->createPageIfNeeded( PageRoutes::SIGN_IN, 'Авторизация', '[fs_lms_login_form]' );
		$generator->createPageIfNeeded( PageRoutes::SIGN_UP, 'Регистрация', '[fs_lms_register_form]' );
		$generator->createPageIfNeeded( PageRoutes::USER_PROFILE, 'Личный кабинет', '[fs_lms_profile]' );
	}
}
