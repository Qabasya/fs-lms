<?php

declare( strict_types=1 );

namespace Inc\Core\Assets;

use Inc\Core\BaseController;
use Inc\Enums\Wp\AjaxHook;
use Inc\Enums\Wp\Nonce;
use Inc\Enums\Wp\PageRoutes;
use Inc\Services\Security\FormGuardService;
use Inc\Services\Subject\PostTypeResolver;

/**
 * Class FrontendAssets
 *
 * Ассеты публичной части: маршрутизация «изолированный SPA vs общий стек»
 * (плеер/КЕГЭ/контрольная/кабинет → BundleLoader), базовый фронт-стек
 * и локализация window-переменных публичных форм (apply/join/assessment).
 *
 * Выделен из Core\Enqueue (Т14.4).
 *
 * @package Inc\Core\Assets
 */
class FrontendAssets extends BaseController {

	/** Хендл публичного бандла — к нему цепляются window-переменные страниц сайта. */
	private const FRONTEND_SCRIPT_HANDLE = 'fs-lms-frontend-script';

	public function __construct(
		private readonly BundleLoader     $bundles,
		private readonly FormGuardService $formGuard,
	) {
		parent::__construct();
	}

	/**
	 * Подключение ресурсов на фронтенде (хук wp_enqueue_scripts).
	 *
	 * @return void
	 */
	public function enqueue(): void {
		// Изолированные SPA-бандлы: каждый рендерится на своём bare-шелле и
		// НЕ должен тянуть общий frontend/theme-стек. Порядок важен: станция КЕГЭ
		// проверяется раньше générique-маршрута контрольной.
		$isolated = array(
			'fs_lms_is_player_route'     => fn() => $this->bundles->enqueuePlayer(),
			'fs_lms_is_kege_route'       => fn() => $this->bundles->enqueueKege(),
			'fs_lms_is_assessment_route' => fn() => $this->bundles->enqueueAssessment(),
		);

		foreach ( $isolated as $filter => $enqueue ) {
			if ( apply_filters( $filter, false ) ) {
				$enqueue();
				return;
			}
		}

		// Личный кабинет — тоже изолированный полноэкранный SPA.
		if ( is_user_logged_in() && PageRoutes::UserProfile->isCurrent() ) {
			$this->bundles->enqueueProfile();
			return;
		}

		$this->enqueueFrontendBase();

		foreach ( $this->localizations() as $varName => $data ) {
			if ( null !== $data ) {
				wp_localize_script( self::FRONTEND_SCRIPT_HANDLE, $varName, $data );
			}
		}

		// MathJax v3 — рендеринг LaTeX-формул на публичной странице задания.
		// Плеер урока грузит MathJax своим бандлом (BundleLoader::enqueuePlayer).
		if ( is_singular() && PostTypeResolver::isTaskPostType( (string) get_post_type() ) ) {
			$this->bundles->enqueueMathJax();
		}
	}

	/**
	 * Базовый публичный стек: шрифт интерфейса, общий и фронтовый бандлы.
	 *
	 * Версия — filemtime(), как в админке: хостинг кеширует статику на неделю, и с
	 * постоянным `?ver=` браузер после деплоя продолжал бы отдавать старый бандл.
	 *
	 * @return void
	 */
	private function enqueueFrontendBase(): void {
		$this->bundles->enqueueUiFont();

		wp_enqueue_style(
			'fs-lms-common-style',
			$this->url( 'assets/css/common.min.css' ),
			array(),
			filemtime( $this->path( 'assets/css/common.min.css' ) )
		);

		wp_enqueue_style(
			'fs-lms-frontend-style',
			$this->url( 'assets/css/frontend.min.css' ),
			array( 'fs-lms-common-style', 'dashicons' ),
			filemtime( $this->path( 'assets/css/frontend.min.css' ) )
		);

		wp_enqueue_script(
			'fs-lms-common-script',
			$this->url( 'assets/js/common.min.js' ),
			array( 'jquery' ),
			filemtime( $this->path( 'assets/js/common.min.js' ) ),
			true
		);

		wp_enqueue_script(
			self::FRONTEND_SCRIPT_HANDLE,
			$this->url( 'assets/js/frontend.min.js' ),
			array( 'jquery', 'fs-lms-common-script' ),
			filemtime( $this->path( 'assets/js/frontend.min.js' ) ),
			true
		);
	}

	/**
	 * Реестр window-переменных публичных страниц: имя → данные (null — не нужна здесь).
	 *
	 * @return array<string, array<string, mixed>|null>
	 */
	private function localizations(): array {
		$isAssessment = is_singular() && PostTypeResolver::isAssessmentPostType( (string) get_post_type() );

		return array(
			'fs_lms_apply_vars'      => PageRoutes::Apply->isCurrent() ? $this->applyVars() : null,
			'fs_lms_login_vars'      => PageRoutes::SignIn->isCurrent() ? $this->loginVars() : null,
			// Модульные скины контрольной (EgeComputer и т.п.) на общем стеке —
			// данные попытки из единого провайдера BundleLoader::assessmentVars().
			'fs_lms_assessment_vars' => $isAssessment ? $this->bundles->assessmentVars() : null,
			'fs_lms_join_vars'       => 'join' === get_query_var( 'fs_lms_page' ) ? $this->joinVars() : null,
		);
	}

	/**
	 * Форма создания заявки (`/lms/apply`).
	 *
	 * Опциональные модули (напр. SmartCaptcha) дописывают свои переменные
	 * (`captcha_key`) фильтром и сами грузят свои внешние скрипты — ядро о них не знает.
	 *
	 * @return array<string, mixed>
	 */
	private function applyVars(): array {
		return (array) apply_filters( 'fs_lms_apply_vars', array(
			'ajax_url'   => admin_url( 'admin-ajax.php' ),
			'hp_field'   => $this->formGuard->honeypotField(),
			'form_token' => $this->formGuard->timestampToken(),
			'actions'    => array(
				'send_otp'       => AjaxHook::SendOtpCode->jsAction(),
				'create'         => AjaxHook::CreateApplication->jsAction(),
				'check_username' => AjaxHook::CheckUsernameAvailable->jsAction(),
			),
			'nonces'     => array(
				'apply'          => Nonce::Apply->create(),
				'verify_otp'     => Nonce::VerifyOtp->create(),
				'check_username' => Nonce::CheckUsernameAvailable->create(),
			),
		) );
	}

	/**
	 * Форма входа (`/sign-in/`).
	 *
	 * Ядро про капчу не знает: модуль SmartCaptcha дописывает `captcha_key` фильтром.
	 *
	 * @return array<string, mixed>
	 */
	private function loginVars(): array {
		return (array) apply_filters( 'fs_lms_login_vars', array(
			'captcha_unavailable' => 'Проверка безопасности не загрузилась — отключите блокировщик рекламы и обновите страницу.',
		) );
	}

	/**
	 * Форма завершения регистрации родителя (`/lms/join`).
	 *
	 * Опциональные модули (напр. DaData) дописывают свои переменные фильтром.
	 *
	 * @return array<string, mixed>
	 */
	private function joinVars(): array {
		return (array) apply_filters( 'fs_lms_join_vars', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'actions'  => array(
				'submit_parent' => AjaxHook::SubmitParentData->jsAction(),
				'check_email'   => AjaxHook::CheckEmailAvailable->jsAction(),
			),
			'nonces'   => array(
				'parent_submit' => Nonce::ParentSubmit->create(),
				'check_email'   => Nonce::CheckEmailAvailable->create(),
			),
		) );
	}
}
