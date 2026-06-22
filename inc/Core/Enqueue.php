<?php

declare(strict_types=1);

namespace Inc\Core;

use Inc\Contracts\ServiceInterface;
use Inc\Enums\Wp\AjaxHook;
use Inc\Enums\Wp\Nonce;
use Inc\Enums\Wp\PageRoutes;
use Inc\Repositories\OptionsRepositories\TaxonomyRepository;
use Inc\Services\Application\ApplicationSettingsService;
use Inc\Services\Captcha\CaptchaService;
use Inc\Services\Security\FormGuardService;
use Inc\Services\Subject\PostTypeResolver;
use Inc\Services\Shared\PluginConfig;
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
	 * @param CaptchaService     $captchaService      Сервис капчи (для получения site key)
	 */
	public function __construct(
		private readonly TaxonomyRepository $taxonomy_repository,
		private readonly CaptchaService     $captchaService,
		private readonly PluginConfig       $pluginConfig,
		private readonly FormGuardService   $formGuard,
		private readonly ApplicationSettingsService $applicationSettings,
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
		$is_task_cpt     = $screen && PostTypeResolver::isTaskPostType( $screen->post_type );
		$is_lesson_cpt   = $screen && PostTypeResolver::isLessonPostType( $screen->post_type );
		$is_work_cpt     = $screen && PostTypeResolver::isWorkPostType( $screen->post_type );
		$is_course_cpt   = $screen && PostTypeResolver::isCoursePostType( $screen->post_type );
		$is_problems_cpt = $screen && PostTypeResolver::problems() === $screen->post_type;
		$is_article_cpt  = $screen && PostTypeResolver::isArticlePostType( $screen->post_type );

		// Подключаем ресурсы ТОЛЬКО на страницах плагина или наших CPT
		if ( ! $is_plugin_page && ! $is_task_cpt && ! $is_lesson_cpt && ! $is_work_cpt && ! $is_course_cpt && ! $is_problems_cpt && ! $is_article_cpt ) {
			return;
		}

		// wp_enqueue_media() — подключает медиа-библиотеку WordPress (для загрузки изображений)
		wp_enqueue_media();

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
					'authorCourse'      => Nonce::AuthorCourse->create(),
				),
				'ajax_actions' => AjaxHook::toJsArray(),
			)
		);
	}

	/**
	 * Подключение ресурсов на фронтенде (публичная часть сайта).
	 *
	 * @return void
	 */
	public function enqueue_frontend_assets(): void {
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
			wp_localize_script(
				'fs-lms-frontend-script',
				'fs_lms_apply_vars',
				array(
					'ajax_url'    => admin_url( 'admin-ajax.php' ),
					'captcha_key' => $this->captchaService->getSiteKey(),
					'hp_field'    => $this->formGuard->honeypotField(),
					'form_token'  => $this->formGuard->timestampToken(),
					'actions'     => array(
						'send_otp'          => AjaxHook::SendOtpCode->jsAction(),
						'create'            => AjaxHook::CreateApplication->jsAction(),
						'check_username'    => AjaxHook::CheckUsernameAvailable->jsAction(),
						'validate_code'     => AjaxHook::ValidateDirectionCode->jsAction(),
					),
					'nonces'      => array(
						'apply'            => Nonce::Apply->create(),
						'verify_otp'       => Nonce::VerifyOtp->create(),
						'check_username'   => Nonce::CheckUsernameAvailable->create(),
					),
					'bind_to_subject' => $this->applicationSettings->isBindToSubject(),
				)
			);

			// Скрипт Yandex SmartCaptcha — только если ключ задан.
			// Зависит от нашего бандла: он первым ставит window.__fsSmartCaptchaReady,
			// который вызовет onload-колбэк Яндекса для рендера невидимого виджета.
			if ( '' !== $this->captchaService->getSiteKey() ) {
				wp_enqueue_script(
					'fs-lms-smartcaptcha',
					'https://smartcaptcha.yandexcloud.net/captcha.js?render=onload&onload=__fsSmartCaptchaReady',
					array( 'fs-lms-frontend-script' ),
					null,
					true
				);
			}
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
					),
					'nonces'   => array(
						'setLessonVisibility' => Nonce::SetLessonVisibility->create(),
						'saveSchedule'        => Nonce::SaveSchedule->create(),
						'assignCourse'        => Nonce::AssignCourse->create(),
						'authorCourse'        => Nonce::AuthorCourse->create(),
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
					'ajax_url' => admin_url( 'admin-ajax.php' ),
					'action'   => AjaxHook::MarkStepProgress->jsAction(),
					'nonce'    => Nonce::MarkStepProgress->create(),
				)
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
			wp_localize_script(
				'fs-lms-frontend-script',
				'fs_lms_join_vars',
				array(
					'ajax_url'     => admin_url( 'admin-ajax.php' ),
					'dadata_token' => $this->pluginConfig->dadataToken(),
					'actions'      => array(
						'submit_parent' => AjaxHook::SubmitParentData->jsAction(),
						'check_email'   => AjaxHook::CheckEmailAvailable->jsAction(),
					),
					'nonces'       => array(
						'parent_submit' => Nonce::ParentSubmit->create(),
						'check_email'   => Nonce::CheckEmailAvailable->create(),
					),
				)
			);
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
	 * Глобально рендерит HTML модальных окон в админке.
	 * Вызывается на хуке 'admin_footer'.
	 *
	 * @return void
	 */
	public function render_confirm_modal(): void {
		$page = sanitize_text_field( $_GET['page'] ?? '' );

		// Показываем модалки только на страницах нашего плагина
		if ( ! str_starts_with( $page, 'fs_' ) && ! str_starts_with( $page, 'student_' ) ) {
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