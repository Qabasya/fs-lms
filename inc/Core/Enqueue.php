<?php

declare(strict_types=1);

namespace Inc\Core;

use Inc\Contracts\ServiceInterface;
use Inc\Enums\Wp\AjaxHook;
use Inc\Enums\Wp\Nonce;
use Inc\Enums\Wp\PageRoutes;
use Inc\Repositories\OptionsRepositories\TaxonomyRepository;
use Inc\Services\Application\ApplicationSettingsService;
use Inc\Services\Security\FormGuardService;
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
		private readonly ApplicationSettingsService $applicationSettings,
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
		$screen = get_current_screen();
		$page   = $this->sanitizeText( 'page', 'GET' );

		// str_starts_with() — проверяет начало строки (PHP 8.0)
		$is_plugin_page  = str_starts_with( $page, 'fs_' ) || str_starts_with( $page, 'student_' );
		$is_task_cpt        = $screen && PostTypeResolver::isTaskPostType( $screen->post_type );
		$is_lesson_cpt      = $screen && PostTypeResolver::isLessonPostType( $screen->post_type );
		$is_work_cpt        = $screen && PostTypeResolver::isWorkPostType( $screen->post_type );
		$is_assessment_cpt  = $screen && PostTypeResolver::isAssessmentPostType( $screen->post_type );
		$is_course_cpt      = $screen && PostTypeResolver::isCoursePostType( $screen->post_type );
		$is_problems_cpt    = $screen && PostTypeResolver::problems() === $screen->post_type;
		$is_article_cpt     = $screen && PostTypeResolver::isArticlePostType( $screen->post_type );

		// Подключаем ресурсы ТОЛЬКО на страницах плагина или наших CPT
		if ( ! $is_plugin_page && ! $is_task_cpt && ! $is_lesson_cpt && ! $is_work_cpt && ! $is_assessment_cpt && ! $is_course_cpt && ! $is_problems_cpt && ! $is_article_cpt ) {
			return;
		}

		// wp_enqueue_media() — подключает медиа-библиотеку WordPress (для загрузки изображений)
		wp_enqueue_media();

		// На страницах CPT уроков и курсов нужен полный стек TinyMCE для wp.editor.initialize()
		// в редакторе шагов. wp_enqueue_editor() гарантирует загрузку tinymce + wp-tinymce.
		if ( $is_lesson_cpt || $is_course_cpt ) {
			wp_enqueue_editor();
		}

		// Font Awesome — иконки для интерфейса
		wp_enqueue_style(
			'fs-lms-fontawesome',
			'https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.7.2/css/all.min.css',
			array(),
			null
		);

		// filemtime() — используется для версионирования (кеш-бастинг)
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

		$script_handle = 'fs-lms-admin-script';

		wp_enqueue_script(
			'fs-lms-common-script',
			$this->url( 'assets/js/common.min.js' ),
			array( 'jquery' ),
			filemtime( $this->path( 'assets/js/common.min.js' ) ),
			true
		);

		wp_enqueue_script(
			$script_handle,
			$this->url( 'assets/js/admin.min.js' ),
			array( 'jquery', 'wp-api', 'wp-i18n', 'editor', 'quicktags' ),
			filemtime( $this->path( 'assets/js/admin.min.js' ) ),
			true
		);

		// === Контекстная локализация для страниц CPT уроков ===
		if ( $is_lesson_cpt ) {
			$lesson_subject = PostTypeResolver::subjectFromLessonPostType( $screen->post_type );
			wp_localize_script(
				$script_handle,
				'fs_lms_lesson_vars',
				array(
					'ajax_url'    => admin_url( 'admin-ajax.php' ),
					'subject_key' => $lesson_subject,
					'nonces'      => array(
						'authorLesson' => Nonce::AuthorLesson->create(),
					),
				)
			);
		}

		// === Контекстная локализация для страниц CPT работ (нужен task-modal для создания задания) ===
		if ( $is_work_cpt ) {
			$work_subject = PostTypeResolver::subjectFromWorkPostType( $screen->post_type );
			wp_localize_script(
				$script_handle,
				'fs_lms_task_data',
				array(
					'ajax_url'            => admin_url( 'admin-ajax.php' ),
					'security'            => Nonce::TaskCreation->create(),
					'subject_key'         => $work_subject,
					'post_type'           => PostTypeResolver::tasks( $work_subject ),
					'required_taxonomies' => $this->getRequiredTaxonomies( $work_subject ),
				)
			);
		}

		// === Контекстная локализация для страниц CPT заданий ===
		if ( $is_task_cpt ) {
			$subject_key = PostTypeResolver::subjectFromTaskPostType( $screen->post_type );

			wp_localize_script(
				$script_handle,
				'fs_lms_task_data',
				array(
					'ajax_url'            => admin_url( 'admin-ajax.php' ),
					'security'            => Nonce::TaskCreation->create(),
					'subject_key'         => $subject_key,
					'post_type'           => $screen->post_type,
					'required_taxonomies' => $this->getRequiredTaxonomies( $subject_key ),
				)
			);
		}
		// === Контекстная локализация для страниц предметов (fs_subject_*) ===
		elseif ( str_starts_with( $page, 'fs_subject_' ) ) {
			$subject_key = substr( $page, strlen( 'fs_subject_' ) );
			wp_localize_script(
				$script_handle,
				'fs_lms_task_data',
				array(
					'ajax_url'            => admin_url( 'admin-ajax.php' ),
					'security'            => Nonce::TaskCreation->create(),
					'subject_key'         => $subject_key,
					'post_type'           => PostTypeResolver::tasks( $subject_key ),
					'required_taxonomies' => $this->getRequiredTaxonomies( $subject_key ),
				)
			);
			// inline-edit-post — скрипт для быстрого редактирования постов в админке
			wp_enqueue_script( 'inline-edit-post' );
		}

		// === Переменные для inline-редактора задач (Phase F, Этап 6) ===
		$needs_task_editor = $is_task_cpt || $is_lesson_cpt || $is_work_cpt || $is_course_cpt
			|| str_starts_with( $page, 'fs_subject_' );
		if ( $needs_task_editor ) {
			wp_localize_script(
				$script_handle,
				'fs_lms_task_editor_vars',
				array(
					'ajax_url' => admin_url( 'admin-ajax.php' ),
					'schema'   => $this->templateRegistry->allEditorSchemas(),
					'nonces'   => array(
						'taskContent' => Nonce::TaskContent->create(),
					),
					'actions'  => array(
						'saveTaskContent'   => AjaxHook::SaveTaskContent->jsAction(),
						'getTaskEditorForm' => AjaxHook::GetTaskEditorForm->jsAction(),
					),
				)
			);
		}

		// === Переменные для таблицы заявок ===
		if ( 'fs_lms_userlist' === $page ) {
			wp_localize_script(
				$script_handle,
				'fs_lms_applications_vars',
				array(
					'nonces' => array(
						'trash'                   => Nonce::TrashApplication->create(),
						'edit'                    => Nonce::EditApplication->create(),
						'review'                  => Nonce::ReviewApplication->create(),
						'enroll'                  => Nonce::Enroll->create(),
						'manager'                 => Nonce::Manager->create(),
						'revealPii'               => Nonce::RevealPii->create(),
						'updatePerson'            => Nonce::UpdatePerson->create(),
						'exportPii'               => Nonce::ExportPii->create(),
						'deletePii'               => Nonce::RequestPiiDeletion->create(),
						'restoreFromArchive'      => Nonce::RestoreFromArchive->create(),
						'selectExistingParent'    => Nonce::SelectExistingParent->create(),
						'removeParentAssignment'  => Nonce::RemoveParentAssignment->create(),
					),
				)
			);
		}

		// === Глобальные переменные для всех страниц админки нашего плагина ===
		wp_localize_script(
			$script_handle,
			'fs_lms_vars',
			array(
				'ajaxurl'      => admin_url( 'admin-ajax.php' ),
				'nonces'       => array(
					'subject'           => Nonce::Subject->create(),
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
				),
				'ajax_actions' => AjaxHook::toJsArray(),
			)
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
		wp_enqueue_style(
			'fs-lms-profile-style',
			$this->url( 'assets/css/profile.min.css' ),
			array(),
			filemtime( $this->path( 'assets/css/profile.min.css' ) )
		);

		wp_enqueue_script(
			'fs-lms-profile-script',
			$this->url( 'assets/js/profile.min.js' ),
			array(),
			filemtime( $this->path( 'assets/js/profile.min.js' ) ),
			true
		);

		wp_localize_script(
			'fs-lms-profile-script',
			'fsProfile',
			$this->profileResolver->jsConfig( get_current_user_id() )
		);
	}

	/**
	 * Подключение ресурсов на фронтенде (публичная часть сайта).
	 *
	 * @return void
	 */
	public function enqueue_frontend_assets(): void {
		// Личный кабинет — изолированный полноэкранный SPA: грузим только его бандл,
		// без общего frontend/theme-стека, чтобы не мешать вёрстке кабинета.
		if ( is_user_logged_in() && PageRoutes::UserProfile->isCurrent() ) {
			$this->enqueue_profile_assets();
			return;
		}

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
			'fs-lms-frontend-script',
			$this->url( 'assets/js/frontend.min.js' ),
			array( 'jquery', 'fs-lms-common-script' ),
			$this->plugin_version,
			true
		);

		// === Переменные для формы создания заявки (/lms/apply) ===
		// PageRoutes::Apply->isCurrent() — проверка, находимся ли на странице создания заявки
		if ( PageRoutes::Apply->isCurrent() ) {
			$apply_vars = array(
				'ajax_url'   => admin_url( 'admin-ajax.php' ),
				'hp_field'   => $this->formGuard->honeypotField(),
				'form_token' => $this->formGuard->timestampToken(),
				'actions'    => array(
					'send_otp'       => AjaxHook::SendOtpCode->jsAction(),
					'create'         => AjaxHook::CreateApplication->jsAction(),
					'check_username' => AjaxHook::CheckUsernameAvailable->jsAction(),
					'validate_code'  => AjaxHook::ValidateDirectionCode->jsAction(),
				),
				'nonces'     => array(
					'apply'          => Nonce::Apply->create(),
					'verify_otp'     => Nonce::VerifyOtp->create(),
					'check_username' => Nonce::CheckUsernameAvailable->create(),
				),
				'bind_to_subject' => $this->applicationSettings->isBindToSubject(),
			);

			// Опциональные модули (напр. SmartCaptcha) дописывают свои переменные (captcha_key)
			// и сами грузят свои внешние скрипты. Ядро о них не знает.
			$apply_vars = apply_filters( 'fs_lms_apply_vars', $apply_vars );

			wp_localize_script( 'fs-lms-frontend-script', 'fs_lms_apply_vars', $apply_vars );
		}

		// === Кокпит группы преподавателя ===
		if ( PageRoutes::GroupCockpit->isCurrent() ) {
			wp_localize_script(
				'fs-lms-frontend-script',
				'fs_lms_cockpit_vars',
				array(
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
				)
			);

			wp_localize_script(
				'fs-lms-frontend-script',
				'fs_lms_submission_vars',
				array(
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
				)
			);

			// Пошаговый плеер урока (?gl=)
			wp_localize_script(
				'fs-lms-frontend-script',
				'fs_lms_player_vars',
				array(
					'ajax_url'           => admin_url( 'admin-ajax.php' ),
					'action'             => AjaxHook::MarkStepProgress->jsAction(),
					'nonce'              => Nonce::MarkStepProgress->create(),
					'submit_task_action' => AjaxHook::SubmitTaskAnswer->jsAction(),
					'submit_task_nonce'  => Nonce::SubmitTaskAnswer->create(),
				)
			);

			// MathJax v3 — рендеринг LaTeX-формул \(...\) и \[...\] в тексте шагов урока.
			// Конфиг должен быть до загрузки скрипта, поэтому 'before'.
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

		// === Страница прохождения контрольной / экзамена ===
		if ( is_singular() && PostTypeResolver::isAssessmentPostType( (string) get_post_type() ) ) {
			wp_localize_script(
				'fs-lms-frontend-script',
				'fs_lms_assessment_vars',
				array(
					'ajax_url' => admin_url( 'admin-ajax.php' ),
					'actions'  => array(
						'startAttempt'     => AjaxHook::StartAttempt->jsAction(),
						'saveAttemptAnswer' => AjaxHook::SaveAttemptAnswer->jsAction(),
						'submitAttempt'    => AjaxHook::SubmitAttempt->jsAction(),
						'getAttemptResult' => AjaxHook::GetAttemptResult->jsAction(),
					),
					'nonces'   => array(
						'startAttempt'  => Nonce::StartAttempt->create(),
						'submitAttempt' => Nonce::SubmitAttempt->create(),
					),
				)
			);
		}

		// === Переменные для формы завершения регистрации родителя (/lms/join) ===
		if ( 'join' === get_query_var( 'fs_lms_page' ) ) {
			$join_vars = array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'actions'  => array(
					'submit_parent' => AjaxHook::SubmitParentData->jsAction(),
					'check_email'   => AjaxHook::CheckEmailAvailable->jsAction(),
				),
				'nonces'   => array(
					'parent_submit' => Nonce::ParentSubmit->create(),
					'check_email'   => Nonce::CheckEmailAvailable->create(),
				),
			);

			// Опциональные модули (напр. DaData) дописывают свои переменные. Ядро о них не знает.
			$join_vars = apply_filters( 'fs_lms_join_vars', $join_vars );

			wp_localize_script( 'fs-lms-frontend-script', 'fs_lms_join_vars', $join_vars );
		}
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