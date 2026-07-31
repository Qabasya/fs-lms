<?php

declare( strict_types=1 );

namespace Inc\Controllers\Pages;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\DTO\Assessment\AssessmentDTO;
use Inc\Enums\Wp\PageRoutes;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Services\Assessment\AttemptPageService;
use Inc\Services\Subject\PostTypeResolver;
use Inc\Services\Shared\ThemeCompatService;

/**
 * Class AssessmentPageController
 *
 * Подменяет шаблон для CPT {key}_assessments на фронтенде.
 *
 * ── Конвейер стадий прохождения (D16.4) ───────────────────────────────────────
 * Страница экзамена проходит три декуплированные стадии, разведённые по партиалам:
 *
 *   [intro]  → attempt-intro.php   (стартовый экран: описание + правила + «Начать»)
 *   [tasks]  → attempt.php         (форма активной попытки / рендерер kind)
 *   [result] → attempt.php         (экран результата завершённой попытки)
 *
 * Дефолтный рендерер `attempt.php` сам выбирает стадию по состоянию попытки и
 * подключает интро-партиал только в ветке «нет активной/последней попытки».
 *
 * ── Точки замены (фильтры) ────────────────────────────────────────────────────
 * Чтобы поменять шаг ДО заданий — правится ОДНО место: партиал интро или фильтр.
 *   - {@see RENDERER_FILTER} — заменить весь плеер (стадию tasks/result целиком);
 *   - {@see INTRO_FILTER}    — заменить только стартовый экран (стадию intro).
 * КЕГЭ-модуль уже имеет свой интро (kege/entry.php) и рендерер — он остаётся на
 * своём контракте (D16.6) и не задействует INTRO_FILTER.
 *
 * @package Inc\Controllers\Pages
 */
class AssessmentPageController extends BaseController implements ServiceInterface {

	/**
	 * WP filter: выбор шаблона-рендерера плеера экзамена (T7.19).
	 * Модули регистрируют свой скин через:
	 *   add_filter('fs_lms_assessment_renderer', fn($tpl, $kind, $subject) => 'путь/к/скину.php', 10, 3)
	 */
	public const RENDERER_FILTER = 'fs_lms_assessment_renderer';

	/**
	 * WP filter: выбор партиала интро-шага (стартового экрана) — зеркало
	 * {@see RENDERER_FILTER} (D16.4, T16.13). Позволяет модулю/скину заменить
	 * ТОЛЬКО стартовый экран, не трогая рендерер заданий:
	 *   add_filter('fs_lms_assessment_intro', fn($partial, $kind, $subject) => 'путь/к/intro.php', 10, 3)
	 */
	public const INTRO_FILTER = 'fs_lms_assessment_intro';

	/**
	 * WP filter: признак «страница прохождения контрольной на bare-шелле плеера»
	 * (Эпик 15, T15.1/T15.2) — по нему `Enqueue::enqueue_frontend_assets()`
	 * подключает изолированный бандл `assessment.min.css/js` вместо темы сайта.
	 * Взводится только для дефолтного рендерера (attempt.php); модульные скины
	 * (напр. EgeComputer) намеренно оставлены на старом ThemeCompatService-флоу —
	 * см. T15.9.
	 */
	public const ROUTE_FILTER = 'fs_lms_is_assessment_route';

	/**
	 * WP filter: признак «страница станции КЕГЭ» — свой bare-документ
	 * (см. templates/frontend/assessment/ege-computer.php), собственный
	 * изолированный бандл (Enqueue::enqueue_kege_assets()). Визуально не
	 * совпадает с générique-шеллом ROUTE_FILTER, поэтому отдельный флаг.
	 * Взводит Inc\Modules\EgeComputer\EgeComputerModule::resolveRenderer().
	 */
	public const KEGE_ROUTE_FILTER = 'fs_lms_is_kege_route';

	public function __construct(
		private readonly AssessmentManager           $assessments,
		private readonly AttemptPageService          $pageService,
	) {
		parent::__construct();
	}

	public function register(): void {
		add_filter( 'template_include', array( $this, 'loadTemplate' ) );
	}

	public function loadTemplate( string $template ): string {
		if ( ! is_singular() ) {
			return $template;
		}

		$post = get_post();
		if ( ! $post || ! PostTypeResolver::isAssessmentPostType( $post->post_type ) ) {
			return $template;
		}

		$assessment = $this->assessments->get( $post->ID );
		if ( ! $assessment ) {
			return $template;
		}

		// Гард доступа: контрольная остаётся publicly_queryable ради плеера, поэтому
		// доступ закрываем здесь. Гость → логин с возвратом на эту же ссылку.
		$userId = get_current_user_id();
		if ( ! $userId ) {
			// Логин-URL внутренний и доверенный — редиректим как в LessonPlayerController.
			wp_redirect( wp_login_url( get_permalink( $post->ID ) ?: home_url( '/' ) ) );
			exit;
		}

		// Нет ученика или доступа → 404 (не раскрываем наличие контрольной).
		$page = $this->pageService->build( $assessment, $userId );
		if ( null === $page ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			return get_404_template();
		}

		// Остаётся открытой по пермалинку — запрещаем индексацию.
		header( 'X-Robots-Tag: noindex, nofollow', true );

		// Переменные шаблонов прохождения (attempt.php, интро, шелл).
		$person         = $page->person;
		$activeAttempt  = $page->activeAttempt;
		$lastAttempt    = $page->lastAttempt;
		$examInProgress = $page->examInProgress;
		$taskViews      = $page->taskViews;
		$resultPerTask  = $page->resultPerTask;
		$outcome        = $page->outcome;
		$outcomeState   = $page->outcomeState;
		$canRetry       = $page->canRetry;
		$now            = $page->now;

		$defaultTemplate = $this->path( 'templates/frontend/assessment/attempt.php' );
		$template        = $this->resolveRenderer( $assessment, $defaultTemplate );
		$introTemplate   = $this->resolveIntro( $assessment );
		$backUrl       = $this->resolveBackUrl();

		// T15.1: дефолтный рендерер получает générique bare-шелл плеера (см. ROUTE_FILTER).
		if ( $defaultTemplate === $template ) {
			add_filter( self::ROUTE_FILTER, '__return_true' );

			include $this->path( 'templates/frontend/assessment/attempt-shell-header.php' );
			include $template;
			include $this->path( 'templates/frontend/assessment/attempt-shell-footer.php' );
			exit;
		}

		// Станция КЕГЭ: собственный bare-документ (своя шапка/таймер/сайдбар —
		// см. KEGE_ROUTE_FILTER, взводится в EgeComputerModule::resolveRenderer()).
		if ( apply_filters( self::KEGE_ROUTE_FILTER, false ) ) {
			include $template;
			exit;
		}

		ThemeCompatService::header();
		include $template;
		ThemeCompatService::footer();
		exit;
	}

	/**
	 * Рендерер страницы: дефолтный `attempt.php` либо заменённый модулем (T7.19).
	 *
	 * @param AssessmentDTO $assessment      Контрольная
	 * @param string        $defaultTemplate Путь дефолтного рендерера
	 */
	private function resolveRenderer( AssessmentDTO $assessment, string $defaultTemplate ): string {
		$template = (string) apply_filters(
			self::RENDERER_FILTER,
			$defaultTemplate,
			$assessment->kind->value,
			$assessment->subjectKey
		);

		return file_exists( $template ) ? $template : $defaultTemplate;
	}

	/**
	 * Партиал интро-шага: дефолтный либо заменённый модулем/скином (D16.4).
	 *
	 * @param AssessmentDTO $assessment Контрольная
	 */
	private function resolveIntro( AssessmentDTO $assessment ): string {
		$default = $this->path( 'templates/frontend/assessment/attempt-intro.php' );

		$intro = (string) apply_filters(
			self::INTRO_FILTER,
			$default,
			$assessment->kind->value,
			$assessment->subjectKey
		);

		return file_exists( $intro ) ? $intro : $default;
	}

	/**
	 * Ссылка «Вернуться» в шапке bare-шелла (T15.7): возврат в исходный шаг
	 * плеера, если контрольная была открыта из него (`?from_gid=&from_gl=`,
	 * см. `partials/step-assessment.php`), иначе — на `/profile/`.
	 */
	private function resolveBackUrl(): string {
		$fromGid = isset( $_GET['from_gid'] ) ? absint( wp_unslash( $_GET['from_gid'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$fromGl  = isset( $_GET['from_gl'] ) ? absint( wp_unslash( $_GET['from_gl'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $fromGid > 0 && $fromGl > 0 ) {
			return PageRoutes::GroupCockpit->lessonUrl( $fromGid, $fromGl );
		}

		return PageRoutes::UserProfile->url();
	}


}
