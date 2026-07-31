<?php

declare(strict_types=1);

namespace Inc\Core;

use Inc\Contracts\ServiceInterface;
use Inc\Enums\Wp\AjaxHook;
use Inc\Enums\Wp\Nonce;
use Inc\Enums\Wp\PageRoutes;
use Inc\Repositories\OptionsRepositories\TaxonomyRepository;
use Inc\Services\Security\FormGuardService;
use Inc\Core\Assets\AdminScreenContext;
use Inc\Services\Subject\PostTypeResolver;
use Inc\Services\Profile\ProfileViewResolver;
use Inc\Services\Shared\PluginConfig;
use Inc\Services\Template\TemplateRegistry;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class Enqueue
 *
 * Управление подключением скриптов и стилей плагина.
 *
 * @package Inc\Core
 * @implements ServiceInterface
 *
 * ### Основные обязанности:
 *
 * 1. **Подключение стилей** — регистрация и подключение CSS-файлов для админки и фронтенда.
 * 2. **Подключение скриптов** — регистрация JS-файлов с зависимостями.
 * 3. **Локализация данных** — передача PHP-данных (nonce, AJAX-действия, таксономии) в JavaScript.
 * 4. **Рендеринг модалок** — вывод HTML-шаблонов модальных окон подтверждения и уведомлений.
 *
 * ### Архитектурная роль:
 *
 * Делегирует получение обязательных таксономий репозиторию TaxonomyRepository,
 * а версионирование и пути — родительскому классу BaseController.
 */
class Enqueue extends BaseController implements ServiceInterface {

	/** Хендл админского бандла — к нему цепляются все window-переменные админки. */
	private const ADMIN_SCRIPT_HANDLE = 'fs-lms-admin-script';

	/** Хендл публичного бандла — к нему цепляются window-переменные страниц сайта. */
	private const FRONTEND_SCRIPT_HANDLE = 'fs-lms-frontend-script';

	use Sanitizer;

	/**
	 * Конструктор.
	 *
	 * @param TaxonomyRepository $taxonomy_repository Репозиторий таксономий
	 */
	public function __construct(
		private readonly TaxonomyRepository $taxonomy_repository,
		private readonly PluginConfig       $pluginConfig,
		private readonly FormGuardService   $formGuard,
		private readonly TemplateRegistry   $templateRegistry,
		private readonly ProfileViewResolver $profileResolver,
	) {
		parent::__construct();
	}

	/**
	 * Регистрация всех хуков подключения ресурсов.
	 *
	 * @return void
	 */
	public function register(): void {
		// preconnect к CDN шрифтов — до самой загрузки стиля (экономит RTT).
		add_filter( 'wp_resource_hints', array( $this, 'fontResourceHints' ), 10, 2 );
		// 'admin_enqueue_scripts' — хук для подключения ресурсов в админ-панели
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		// 'wp_enqueue_scripts' — хук для подключения ресурсов на фронтенде
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
		// 'admin_footer' — хук для вывода HTML в подвале админки
		add_action( 'admin_footer', array( $this, 'render_confirm_modal' ) );
	}

	/**
	 * Подключение ресурсов в административной панели.
	 *
	 * @return void
	 */
	public function enqueue_admin_assets(): void {
		// get_current_screen() — возвращает объект текущего экрана админки
		$ctx = AdminScreenContext::from( get_current_screen(), $this->sanitizeText( 'page', 'GET' ) );

		// Подключаем ресурсы ТОЛЬКО на страницах плагина или наших CPT
		if ( ! $ctx->needsAssets() ) {
			return;
		}

		// wp_enqueue_media() — подключает медиа-библиотеку WordPress (для загрузки изображений)
		wp_enqueue_media();

		// На страницах CPT уроков и курсов нужен полный стек TinyMCE для wp.editor.initialize()
		// в редакторе шагов. wp_enqueue_editor() гарантирует загрузку tinymce + wp-tinymce.
		if ( $ctx->needsEditor() ) {
			wp_enqueue_editor();
		}

		$this->enqueueAdminBase();

		// Страница предмета: быстрое редактирование строк нативной таблицы.
		if ( $ctx->isSubjectPage() ) {
			// inline-edit-post — скрипт для быстрого редактирования постов в админке
			wp_enqueue_script( 'inline-edit-post' );
		}

		foreach ( $this->adminLocalizations( $ctx ) as $varName => $data ) {
			if ( null !== $data ) {
				wp_localize_script( self::ADMIN_SCRIPT_HANDLE, $varName, $data );
			}
		}
	}

	/**
	 * Базовый стек админки: шрифт иконок, общий и админский бандлы.
	 *
	 * filemtime() — версионирование (кеш-бастинг).
	 *
	 * @return void
	 */
	private function enqueueAdminBase(): void {
		// Font Awesome — иконки для интерфейса
		wp_enqueue_style(
			'fs-lms-fontawesome',
			'https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.7.2/css/all.min.css',
			array(),
			null
		);

		wp_enqueue_style(
			'fs-lms-common-style',
			$this->url( 'assets/css/common.min.css' ),
			array( 'fs-lms-fontawesome' ),
			filemtime( $this->path( 'assets/css/common.min.css' ) )
		);

		wp_enqueue_style(
			'fs-lms-admin-style',
			$this->url( 'assets/css/admin.min.css' ),
			array( 'wp-components', 'fs-lms-common-style' ),
			filemtime( $this->path( 'assets/css/admin.min.css' ) )
		);

		wp_enqueue_script(
			'fs-lms-common-script',
			$this->url( 'assets/js/common.min.js' ),
			array( 'jquery' ),
			filemtime( $this->path( 'assets/js/common.min.js' ) ),
			true
		);

		wp_enqueue_script(
			self::ADMIN_SCRIPT_HANDLE,
			$this->url( 'assets/js/admin.min.js' ),
			array( 'jquery', 'wp-api', 'wp-i18n', 'editor', 'quicktags' ),
			filemtime( $this->path( 'assets/js/admin.min.js' ) ),
			true
		);
	}

	/**
	 * Реестр window-переменных админки: имя → данные (null — на этом экране не нужна).
	 *
	 * @param AdminScreenContext $ctx Признаки текущего экрана
	 *
	 * @return array<string, array<string, mixed>|null>
	 */
	private function adminLocalizations( AdminScreenContext $ctx ): array {
		return array(
			'fs_lms_lesson_vars'       => $ctx->lesson ? $this->lessonVars( $ctx ) : null,
			// На экране работ нужен task-modal для создания задания.
			'fs_lms_task_data'         => $this->taskDataVars( $ctx ),
			'fs_lms_task_editor_vars'  => $ctx->needsTaskEditor() ? $this->taskEditorVars() : null,
			'fs_lms_applications_vars' => 'fs_lms_userlist' === $ctx->page ? $this->applicationsVars() : null,
			// Глобальные переменные — на всех страницах админки плагина.
			'fs_lms_vars'              => $this->globalAdminVars(),
		);
	}

	/**
	 * Переменные экрана CPT уроков.
	 *
	 * @param AdminScreenContext $ctx Признаки экрана
	 *
	 * @return array<string, mixed>
	 */
	private function lessonVars( AdminScreenContext $ctx ): array {
		return array(
			'ajax_url'    => admin_url( 'admin-ajax.php' ),
			'subject_key' => PostTypeResolver::subjectFromLessonPostType( $ctx->postType ),
			'nonces'      => array(
				'authorLesson' => Nonce::AuthorLesson->create(),
			),
		);
	}

	/**
	 * Данные модалки создания задания — на экранах заданий, работ и страницах предмета.
	 *
	 * @param AdminScreenContext $ctx Признаки экрана
	 *
	 * @return array<string, mixed>|null
	 */
	private function taskDataVars( AdminScreenContext $ctx ): ?array {
		$subjectKey = match ( true ) {
			$ctx->task            => PostTypeResolver::subjectFromTaskPostType( $ctx->postType ),
			$ctx->work            => PostTypeResolver::subjectFromWorkPostType( $ctx->postType ),
			$ctx->isSubjectPage() => $ctx->subjectPageKey(),
			default               => '',
		};

		if ( '' === $subjectKey ) {
			return null;
		}

		return array(
			'ajax_url'            => admin_url( 'admin-ajax.php' ),
			'security'            => Nonce::TaskCreation->create(),
			'subject_key'         => $subjectKey,
			'post_type'           => $ctx->task ? $ctx->postType : PostTypeResolver::tasks( $subjectKey ),
			'required_taxonomies' => $this->getRequiredTaxonomies( $subjectKey ),
		);
	}

	/**
	 * Переменные inline-редактора задач (Phase F, Этап 6).
	 *
	 * @return array<string, mixed>
	 */
	private function taskEditorVars(): array {
		return array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'schema'   => $this->templateRegistry->allEditorSchemas(),
			'nonces'   => array(
				'taskContent' => Nonce::TaskContent->create(),
			),
			'actions'  => array(
				'saveTaskContent'   => AjaxHook::SaveTaskContent->jsAction(),
				'getTaskEditorForm' => AjaxHook::GetTaskEditorForm->jsAction(),
			),
		);
	}

	/**
	 * Переменные таблицы заявок.
	 *
	 * @return array<string, mixed>
	 */
	private function applicationsVars(): array {
		return array(
			'nonces' => array(
				'trash'                  => Nonce::TrashApplication->create(),
				'edit'                   => Nonce::EditApplication->create(),
				'review'                 => Nonce::ReviewApplication->create(),
				'enroll'                 => Nonce::Enroll->create(),
				'manager'                => Nonce::Manager->create(),
				'revealPii'              => Nonce::RevealPii->create(),
				'updatePerson'           => Nonce::UpdatePerson->create(),
				'deletePii'              => Nonce::RequestPiiDeletion->create(),
				'restoreFromArchive'     => Nonce::RestoreFromArchive->create(),
				'selectExistingParent'   => Nonce::SelectExistingParent->create(),
				'removeParentAssignment' => Nonce::RemoveParentAssignment->create(),
			),
		);
	}

	/**
	 * Глобальные переменные всех страниц админки плагина.
	 *
	 * @return array<string, mixed>
	 */
	private function globalAdminVars(): array {
		return array(
			'ajaxurl'          => admin_url( 'admin-ajax.php' ),
			'nonces'           => array(
				'subject'           => Nonce::Subject->create(),
				'subjectBundle'     => Nonce::SubjectBundle->create(),
				'manager'           => Nonce::Manager->create(),
				'expulsion'         => Nonce::Expulsion->create(),
				'deleteGroup'       => Nonce::DeleteGroup->create(),
				'deletePeriod'      => Nonce::DeletePeriod->create(),
				'hardDeleteStudent' => Nonce::HardDeleteStudent->create(),
				'config'            => Nonce::Config->create(),
				'authorLesson'      => Nonce::AuthorLesson->create(),
				'authorWork'        => Nonce::AuthorWork->create(),
				'authorAssessment'  => Nonce::AuthorAssessment->create(),
				'authorCourse'      => Nonce::AuthorCourse->create(),
				'room'              => Nonce::Room->create(),
				'scoreMap'          => Nonce::ScoreMap->create(),
			),
			'ajax_actions'     => AjaxHook::toJsArray(),
			// Фаза 5, D3/D4: URL preview-плеера курса (кнопка «Просмотр» в конструкторе).
			'coursePreviewUrl' => PageRoutes::CoursePreview->url(),
		);
	}

	/**
	 * Единый скелет подключения изолированного бандла: style + script + localize.
	 * Дедуп почти одинаковых блоков (profile/player/assessment/kege) — Р2.6.
	 * Хендлы фиксированы паттерном `fs-lms-{$slug}-style` / `fs-lms-{$slug}-script`.
	 *
	 * @param string               $slug    базовое имя файлов и хендлов (profile/player/…)
	 * @param string               $varName имя window-переменной для wp_localize_script
	 * @param array<string, mixed> $data    данные локализации
	 * @param string[]             $deps    зависимости скрипта (напр. ['jquery'])
	 *
	 * @return void
	 */
	/**
	 * Шрифт интерфейса (Ubuntu, $font-ui) — один на все бандлы.
	 *
	 * Грузим стилем, а не CSS `@import`: последний блокирует рендер и не даёт
	 * воспользоваться preconnect (см. fontResourceHints()).
	 *
	 * @return void
	 */
	private function enqueueUiFont(): void {
		wp_enqueue_style(
			'fs-lms-ubuntu',
			'https://fonts.googleapis.com/css2?family=Ubuntu:wght@400;500;700&display=swap',
			array(),
			null
		);
	}

	private function enqueueBundle( string $slug, string $varName, array $data, array $deps = array() ): void {
		// Изолированные SPA (кабинет, плеер, контрольная, станция) рендерятся на
		// bare-шелле без общего фронт-стека — шрифт интерфейса подключаем здесь.
		$this->enqueueUiFont();

		wp_enqueue_style(
			"fs-lms-{$slug}-style",
			$this->url( "assets/css/{$slug}.min.css" ),
			array(),
			filemtime( $this->path( "assets/css/{$slug}.min.css" ) )
		);

		wp_enqueue_script(
			"fs-lms-{$slug}-script",
			$this->url( "assets/js/{$slug}.min.js" ),
			$deps,
			filemtime( $this->path( "assets/js/{$slug}.min.js" ) ),
			true
		);

		wp_localize_script( "fs-lms-{$slug}-script", $varName, $data );
	}

	/**
	 * MathJax v3 (tex-chtml) + инлайн-конфиг делимитеров \(…\)/\[…\]. Общий для
	 * плеера, контрольной и станции КЕГЭ (Р2.6).
	 *
	 * @return void
	 */
	private function enqueueMathJax(): void {
		wp_enqueue_script(
			'fs-lms-mathjax',
			'https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-chtml.js',
			array(),
			null,
			true
		);
		wp_add_inline_script(
			'fs-lms-mathjax',
			'window.MathJax = { tex: { inlineMath: [["\\\\(", "\\\\)"]], displayMath: [["\\\\[", "\\\\]"]] } };',
			'before'
		);
	}

	/**
	 * Подключение изолированного бандла личного кабинета (/profile/).
	 *
	 * Грузит только profile.min.css/js и локализует window.fsProfile
	 * (роль → состав кабинета + режим доступа) через ProfileViewResolver.
	 *
	 * @return void
	 */
	private function enqueue_profile_assets(): void {
		$this->enqueueBundle( 'profile', 'fsProfile', $this->profileResolver->jsConfig( get_current_user_id() ) );
	}

	/**
	 * Подключение изолированного бандла плеера курса (Эпик 14, D18).
	 *
	 * Грузит только player.min.css/js + MathJax и локализует fs_lms_player_vars.
	 * Общий для двух маршрутов, оба взводят фильтр `fs_lms_is_player_route`
	 * перед рендером player.php: LessonPlayerController (кокпит + ?gl=, ученик)
	 * и CoursePreviewController (/course-preview/?course=, Фаза 5 — предпросмотр
	 * для преподавателя/офиса/автора, без ученика и сохранения прогресса).
	 *
	 * @return void
	 */
	private function enqueue_player_assets(): void {
		$this->enqueueBundle(
			'player',
			'fs_lms_player_vars',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'actions'  => array(
					'markStep'          => AjaxHook::MarkStepProgress->jsAction(),
					'submitTask'        => AjaxHook::SubmitTaskAnswer->jsAction(),
					'submitBatchWork'   => AjaxHook::SubmitBatchWork->jsAction(),
					// #5: dry-run проверка в предпросмотре (гейт canSolvePreview в коллбеке).
					'previewCheckTask'       => AjaxHook::PreviewCheckTask->jsAction(),
					'previewCheckWork'       => AjaxHook::PreviewCheckWork->jsAction(),
					'previewCheckAssessment' => AjaxHook::PreviewCheckAssessment->jsAction(),
				),
				'nonces'   => array(
					'markStep'        => Nonce::MarkStepProgress->create(),
					'submitTask'      => Nonce::SubmitTaskAnswer->create(),
					'submitBatchWork' => Nonce::SubmitBatchWork->create(),
					'previewSolve'    => Nonce::PreviewSolve->create(),
				),
			)
		);

		$this->enqueueMathJax();
	}

	/**
	 * Подключение изолированного бандла страницы контрольной/экзамена (Эпик 15,
	 * T15.1/T15.2) — bare-шелл на токенах плеера, без темы сайта. Взводится
	 * только для дефолтного рендерера `AssessmentPageController` (см. ROUTE_FILTER);
	 * модульные скины (EgeComputer и т.п.) остаются на старом frontend-стеке
	 * ниже — см. ветку `is_singular() && isAssessmentPostType()`.
	 *
	 * @return void
	 */
	private function enqueue_assessment_assets(): void {
		$this->enqueueBundle(
			'assessment',
			'fs_lms_assessment_vars',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'actions'  => array(
					'startAttempt'      => AjaxHook::StartAttempt->jsAction(),
					'saveAttemptAnswer' => AjaxHook::SaveAttemptAnswer->jsAction(),
					'submitAttempt'     => AjaxHook::SubmitAttempt->jsAction(),
					'getAttemptResult'  => AjaxHook::GetAttemptResult->jsAction(),
					// Эпик 13 (D16): двухшаговая загрузка файла ответа («Развёрнутый ответ»).
					'uploadAnswerFile'  => AjaxHook::UploadAnswerFile->jsAction(),
				),
				'nonces'   => array(
					'startAttempt'     => Nonce::StartAttempt->create(),
					'submitAttempt'    => Nonce::SubmitAttempt->create(),
					'uploadAnswerFile' => Nonce::UploadAnswerFile->create(),
				),
			)
		);

		$this->enqueueMathJax();
	}

	/**
	 * Подключение изолированного бандла станции КЕГЭ (T15.10) — bare-документ
	 * на токенах плеера, свой JS/CSS. Модуль EgeComputer (опциональный, см.
	 * inc/Modules/EgeComputer/) взводит fs_lms_is_kege_route в
	 * AssessmentPageController; ядро о модуле не знает, только читает фильтр.
	 *
	 * @return void
	 */
	private function enqueue_kege_assets(): void {
		$this->enqueueBundle(
			'kege',
			'fs_lms_kege_vars',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'actions'  => array(
					'startAttempt'      => AjaxHook::StartAttempt->jsAction(),
					'saveAttemptAnswer' => AjaxHook::SaveAttemptAnswer->jsAction(),
					'submitAttempt'     => AjaxHook::SubmitAttempt->jsAction(),
					'getAttemptResult'  => AjaxHook::GetAttemptResult->jsAction(),
				),
				'nonces'   => array(
					'startAttempt'  => Nonce::StartAttempt->create(),
					'submitAttempt' => Nonce::SubmitAttempt->create(),
				),
			)
		);

		$this->enqueueMathJax();
	}

	/**
	 * Подключение ресурсов на фронтенде (публичная часть сайта).
	 *
	 * @return void
	 */
	public function enqueue_frontend_assets(): void {
		// Изолированные SPA-бандлы: каждый рендерится на своём bare-шелле и
		// НЕ должен тянуть общий frontend/theme-стек. Порядок важен: станция КЕГЭ
		// проверяется раньше générique-маршрута контрольной.
		$isolated = array(
			'fs_lms_is_player_route'     => fn() => $this->enqueue_player_assets(),
			'fs_lms_is_kege_route'       => fn() => $this->enqueue_kege_assets(),
			'fs_lms_is_assessment_route' => fn() => $this->enqueue_assessment_assets(),
		);

		foreach ( $isolated as $filter => $enqueue ) {
			if ( apply_filters( $filter, false ) ) {
				$enqueue();
				return;
			}
		}

		// Личный кабинет — тоже изолированный полноэкранный SPA.
		if ( is_user_logged_in() && PageRoutes::UserProfile->isCurrent() ) {
			$this->enqueue_profile_assets();
			return;
		}

		$this->enqueueFrontendBase();

		foreach ( $this->frontendLocalizations() as $varName => $data ) {
			if ( null !== $data ) {
				wp_localize_script( self::FRONTEND_SCRIPT_HANDLE, $varName, $data );
			}
		}

		// MathJax v3 — рендеринг LaTeX-формул в контенте кокпита (инструкции работ и т.п.).
		if ( PageRoutes::GroupCockpit->isCurrent() ) {
			$this->enqueueMathJax();
		}
	}

	/**
	 * Базовый публичный стек: шрифт иконок, общий и фронтовый бандлы.
	 *
	 * @return void
	 */
	/**
	 * Ранние подключения к CDN шрифтов для публичных страниц.
	 *
	 * @param string[] $hints    Текущие подсказки
	 * @param string   $relation Тип отношения (preconnect/dns-prefetch/…)
	 *
	 * @return string[]
	 */
	public function fontResourceHints( array $hints, string $relation ): array {
		if ( 'preconnect' === $relation && ! is_admin() ) {
			$hints[] = 'https://fonts.googleapis.com';
			$hints[] = array( 'href' => 'https://fonts.gstatic.com', 'crossorigin' => 'anonymous' );
		}

		return $hints;
	}

	private function enqueueFrontendBase(): void {
		$this->enqueueUiFont();

		wp_enqueue_style(
			'fs-lms-fontawesome',
			'https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.7.2/css/all.min.css',
			array(),
			null
		);

		wp_enqueue_style(
			'fs-lms-common-style',
			$this->url( 'assets/css/common.min.css' ),
			array( 'fs-lms-fontawesome' ),
			$this->plugin_version
		);

		wp_enqueue_style(
			'fs-lms-frontend-style',
			$this->url( 'assets/css/frontend.min.css' ),
			array( 'fs-lms-common-style', 'dashicons' ),
			$this->plugin_version
		);

		wp_enqueue_script(
			'fs-lms-common-script',
			$this->url( 'assets/js/common.min.js' ),
			array( 'jquery' ),
			$this->plugin_version,
			true
		);

		wp_enqueue_script(
			self::FRONTEND_SCRIPT_HANDLE,
			$this->url( 'assets/js/frontend.min.js' ),
			array( 'jquery', 'fs-lms-common-script' ),
			$this->plugin_version,
			true
		);
	}

	/**
	 * Реестр window-переменных публичных страниц: имя → данные (null — не нужна здесь).
	 *
	 * @return array<string, array<string, mixed>|null>
	 */
	private function frontendLocalizations(): array {
		$isCockpit    = PageRoutes::GroupCockpit->isCurrent();
		$isAssessment = is_singular() && PostTypeResolver::isAssessmentPostType( (string) get_post_type() );

		return array(
			'fs_lms_apply_vars'      => PageRoutes::Apply->isCurrent() ? $this->applyVars() : null,
			'fs_lms_cockpit_vars'    => $isCockpit ? $this->cockpitVars() : null,
			'fs_lms_submission_vars' => $isCockpit ? $this->submissionVars() : null,
			'fs_lms_assessment_vars' => $isAssessment ? $this->assessmentVars() : null,
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
	 * Кокпит группы преподавателя.
	 *
	 * Плеер урока живёт на своём изолированном бандле (Эпик 14, D18) —
	 * см. {@see enqueue_player_assets()}; здесь остаётся только кокпит.
	 *
	 * @return array<string, mixed>
	 */
	private function cockpitVars(): array {
		return array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'actions'  => array(
				'setLessonVisibility'       => AjaxHook::SetLessonVisibility->jsAction(),
				'removeLessonFromProgram'   => AjaxHook::RemoveLessonFromProgram->jsAction(),
				'getGroupActivity'          => AjaxHook::GetGroupActivity->jsAction(),
				'reorderProgram'            => AjaxHook::ReorderProgram->jsAction(),
				'assignCourse'              => AjaxHook::AssignCourse->jsAction(),
				'addLessonToProgram'        => AjaxHook::AddLessonToProgram->jsAction(),
				'duplicateProgramLesson'    => AjaxHook::DuplicateProgramLesson->jsAction(),
				'saveLessonSchedule'        => AjaxHook::SaveLessonSchedule->jsAction(),
				'getCourseLessonCandidates' => AjaxHook::GetCourseLessonCandidates->jsAction(),
				'getStepSettings'           => AjaxHook::GetStepSettings->jsAction(),
				'saveStepSettings'          => AjaxHook::SaveStepSettings->jsAction(),
				'getTaskAttempts'           => AjaxHook::GetTaskAttempts->jsAction(),
			),
			'nonces'   => array(
				'setLessonVisibility' => Nonce::SetLessonVisibility->create(),
				'saveSchedule'        => Nonce::SaveSchedule->create(),
				'assignCourse'        => Nonce::AssignCourse->create(),
				'authorCourse'        => Nonce::AuthorCourse->create(),
				'stepSettings'        => Nonce::StepSettings->create(),
			),
		);
	}

	/**
	 * Сдача и проверка работ в кокпите.
	 *
	 * @return array<string, mixed>
	 */
	private function submissionVars(): array {
		return array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'actions'  => array(
				'submitWork'          => AjaxHook::SubmitWork->jsAction(),
				'getMySubmissions'    => AjaxHook::GetMySubmissions->jsAction(),
				'saveGrade'           => AjaxHook::SaveGrade->jsAction(),
				'returnSubmission'    => AjaxHook::ReturnSubmission->jsAction(),
				'getGroupSubmissions' => AjaxHook::GetGroupSubmissions->jsAction(),
				'getGradebook'        => AjaxHook::GetGradebook->jsAction(),
			),
			'nonces'   => array(
				'submitWork' => Nonce::SubmitWork->create(),
				'gradeWork'  => Nonce::GradeWork->create(),
			),
		);
	}

	/**
	 * Страница прохождения контрольной / экзамена.
	 *
	 * @return array<string, mixed>
	 */
	private function assessmentVars(): array {
		return array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'actions'  => array(
				'startAttempt'      => AjaxHook::StartAttempt->jsAction(),
				'saveAttemptAnswer' => AjaxHook::SaveAttemptAnswer->jsAction(),
				'submitAttempt'     => AjaxHook::SubmitAttempt->jsAction(),
				'getAttemptResult'  => AjaxHook::GetAttemptResult->jsAction(),
				// Эпик 13 (D16): двухшаговая загрузка файла ответа («Развёрнутый ответ»).
				'uploadAnswerFile'  => AjaxHook::UploadAnswerFile->jsAction(),
			),
			'nonces'   => array(
				'startAttempt'     => Nonce::StartAttempt->create(),
				'submitAttempt'    => Nonce::SubmitAttempt->create(),
				'uploadAnswerFile' => Nonce::UploadAnswerFile->create(),
			),
		);
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

	/**
	 * Возвращает список обязательных таксономий для указанного предмета.
	 *
	 * @param string $subject_key Ключ предмета
	 *
	 * @return array
	 */
	private function getRequiredTaxonomies( string $subject_key ): array {
		return array_values(
			array_map(
				fn( $dto ) => array(
					'slug' => $dto->slug,
					'name' => $dto->name,
				),
				array_filter(
					$this->taxonomy_repository->getBySubject( $subject_key ),
					fn( $dto ) => $dto->is_required
				)
			)
		);
	}

	/**
	 * Плагинный экран админки: меню-страница (fs_/student_) или один из наших CPT.
	 * Должно совпадать с условием подключения ассетов в enqueue_admin_assets(),
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

	public function render_confirm_modal(): void {
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
}