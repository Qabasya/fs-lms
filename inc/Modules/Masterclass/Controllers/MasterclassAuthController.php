<?php

declare( strict_types=1 );

namespace Inc\Modules\Masterclass\Controllers;

use Inc\Core\BaseController;
use Inc\Enums\Auth\CaptchaScope;
use Inc\Enums\Wp\PageRoutes;
use Inc\Modules\Masterclass\Services\MasterclassAccountService;
use Inc\Modules\Masterclass\Services\MasterclassLoginTracker;
use Inc\Shared\PluginLogger;
use Inc\Shared\Traits\RequestContextProvider;

/**
 * Class MasterclassAuthController
 *
 * Вход временного ученика: после входа и при заходе на `/sign-in/` или `/profile/` ребёнка сразу ведут в плеер его
 * мастер-класса (кабинета у него нет), вход попадает в трекер, а целому классу за одним IP снимается лимит
 * смягчённой капчи на время мастер-класса (Ш6).
 *
 * Для обычного ученика, учителя и гостя ничего не меняется: редирект и обход действуют только для живого аккаунта.
 *
 * @package Inc\Modules\Masterclass\Controllers
 */
class MasterclassAuthController extends BaseController {

	use RequestContextProvider;

	public function __construct(
		private readonly MasterclassAccountService $accounts,
		private readonly MasterclassLoginTracker $tracker,
	) {
		parent::__construct();
	}

	public function register(): void {
		// Приоритет 5 — раньше `ProfileController`, иначе ребёнок успеет увидеть кабинет.
		add_action( 'template_redirect', array( $this, 'redirectAccount' ), 5 );
		add_filter( 'login_redirect', array( $this, 'filterLoginRedirect' ), 20, 3 );
		add_action( 'wp_login', array( $this, 'onLogin' ), 10, 2 );
		add_filter( 'fs_lms_captcha_fallback_unlimited', array( $this, 'filterCaptchaBypass' ), 10, 4 );
	}

	/** Залогиненный живой аккаунт на `/sign-in/` и `/profile/` уходит в плеер. */
	public function redirectAccount(): void {
		$url = $this->accountRedirectUrl();
		if ( null === $url ) {
			return;
		}

		wp_safe_redirect( $url );
		exit;
	}

	/** Куда вести текущего посетителя; `null` — оставить как есть. */
	public function accountRedirectUrl(): ?string {
		if ( ! is_user_logged_in() ) {
			return null;
		}

		if ( ! PageRoutes::SignIn->isCurrent() && ! PageRoutes::UserProfile->isCurrent() ) {
			return null;
		}

		return $this->accounts->playerUrlFor( get_current_user_id() );
	}

	/**
	 * Фильтр `login_redirect` (после `UserBehaviorManager::resolveLoginRedirect`): аккаунт мастер-класса — в плеер.
	 *
	 * @param mixed $redirectTo Адрес, который выбрали раньше
	 * @param mixed $requested  Запрошенный адрес
	 * @param mixed $user       Пользователь или ошибка входа
	 */
	public function filterLoginRedirect( mixed $redirectTo, mixed $requested, mixed $user ): mixed {
		if ( ! $user instanceof \WP_User ) {
			return $redirectTo;
		}

		return $this->accounts->playerUrlFor( (int) $user->ID ) ?? $redirectTo;
	}

	/** Хук `wp_login`: сбой учёта входа не должен срывать сам вход. */
	public function onLogin( string $userLogin, \WP_User $user ): void {
		try {
			$this->tracker->onLogin( $user, $this->requestContext()->ip );
		} catch ( \Throwable $e ) {
			PluginLogger::exception( 'MasterclassAuth', $e, array( 'hook' => 'wp_login' ), true );
		}
	}

	/**
	 * Шов Ш6: лимит капчи снимается только для формы входа и только для аккаунта идущего мастер-класса.
	 * Решение, принятое другим подписчиком (`true`), не отменяется.
	 *
	 * @param mixed $unlimited  Решение предыдущих подписчиков
	 * @param mixed $scope      Форма, на которой проверяется капча
	 * @param mixed $identifier Логин из запроса
	 * @param mixed $remoteIp   IP клиента (не используется: обход привязан к аккаунту, а не к адресу)
	 */
	public function filterCaptchaBypass( mixed $unlimited, mixed $scope, mixed $identifier, mixed $remoteIp = '' ): bool {
		if ( true === $unlimited ) {
			return true;
		}

		return CaptchaScope::Login === $scope && $this->tracker->allowsCaptchaBypass( (string) $identifier );
	}
}
