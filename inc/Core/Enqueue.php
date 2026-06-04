<?php

declare(strict_types=1);

namespace Inc\Core;

use Inc\Contracts\ServiceInterface;
use Inc\Enums\AjaxHook;
use Inc\Enums\Nonce;
use Inc\Enums\PageRoutes;
use Inc\Repositories\OptionsRepositories\TaxonomyRepository;
use Inc\Services\CaptchaService;
use Inc\Services\PostTypeResolver;

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
 * 4. **Рендеринг модалки** — вывод HTML-шаблона модального окна подтверждения в админ-футере.
 *
 * ### Архитектурная роль:
 *
 * Делегирует получение обязательных таксономий репозиторию TaxonomyRepository,
 * а версионирование и пути — родительскому классу BaseController.
 */
class Enqueue extends BaseController implements ServiceInterface {

	/**
	 * Конструктор.
	 *
	 * @param TaxonomyRepository $taxonomy_repository Репозиторий таксономий
	 * @param CaptchaService     $captchaService      Сервис капчи (для получения site key)
	 */
	public function __construct(
		private readonly TaxonomyRepository $taxonomy_repository,
		private readonly CaptchaService     $captchaService,
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
		$page   = sanitize_text_field( $_GET['page'] ?? '' );

		// str_starts_with() — проверяет начало строки (PHP 8.0)
		$is_plugin_page = str_starts_with( $page, 'fs_' ) || str_starts_with( $page, 'student_' );
		$is_task_cpt    = $screen && PostTypeResolver::isTaskPostType( $screen->post_type );

		// Подключаем ресурсы ТОЛЬКО на страницах плагина или наших CPT
		if ( ! $is_plugin_page && ! $is_task_cpt ) {
			return;
		}

		// wp_enqueue_media() — подключает медиа-библиотеку WordPress (для загрузки изображений)
		wp_enqueue_media();

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

		// === Переменные для карточки person ===
		if ( 'fs-lms-person-detail' === $page ) {
			wp_localize_script(
				$script_handle,
				'fs_lms_person_vars',
				array(
					'nonces' => array(
						'reveal'               => Nonce::RevealPii->create(),
						'update'               => Nonce::UpdatePerson->create(),
						'delete'               => Nonce::RequestPiiDeletion->create(),
						'export'               => Nonce::ExportPii->create(),
						'add_representative'   => Nonce::AddRepresentative->create(),
						'replace_representative' => Nonce::ReplaceRepresentative->create(),
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
						'trash'        => Nonce::TrashApplication->create(),
						'edit'         => Nonce::EditApplication->create(),
						'review'       => Nonce::ReviewApplication->create(),
						'enroll'       => Nonce::Enroll->create(),
						'manager'      => Nonce::Manager->create(),
						'revealPii'    => Nonce::RevealPii->create(),
						'updatePerson' => Nonce::UpdatePerson->create(),
						'exportPii'    => Nonce::ExportPii->create(),
						'deletePii'    => Nonce::RequestPiiDeletion->create(),
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
					'subject'   => Nonce::Subject->create(),
					'manager'   => Nonce::Manager->create(),
					'expulsion' => Nonce::Expulsion->create(),
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

		// === Переменные для формы создания заявки (/apply) ===
		if ( PageRoutes::Apply->isCurrent() ) {
			wp_localize_script(
				'fs-lms-frontend-script',
				'fs_lms_apply_vars',
				array(
					'ajax_url'    => admin_url( 'admin-ajax.php' ),
					'captcha_key' => $this->captchaService->getSiteKey(),
					'actions'     => array(
						'send_otp' => AjaxHook::SendOtpCode->jsAction(),
						'create'   => AjaxHook::CreateApplication->jsAction(),
					),
					'nonces'      => array(
						'apply'      => Nonce::Apply->create(),
						'verify_otp' => Nonce::VerifyOtp->create(),
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
					'dadata_token' => defined( 'DADATA_API_TOKEN' ) ? DADATA_API_TOKEN : '',
					'actions'      => array(
						'submit_parent' => AjaxHook::SubmitParentData->jsAction(),
					),
					'nonces'       => array(
						'parent_submit' => Nonce::ParentSubmit->create(),
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
	 * Глобально рендерит HTML модального окна подтверждения в админке.
	 *
	 * @return void
	 */
	public function render_confirm_modal(): void {
		$page = sanitize_text_field( $_GET['page'] ?? '' );

		// Показываем модалку только на страницах нашего плагина
		if ( ! str_starts_with( $page, 'fs_' ) && ! str_starts_with( $page, 'student_' ) ) {
			return;
		}

		$modal_path = $this->path( 'templates/admin/components/modals/confirm-modal.php' );

		if ( file_exists( $modal_path ) ) {
			require_once $modal_path;
		}

		$alert_modal_path = $this->path( 'templates/admin/components/modals/alert-modal.php' );

		if ( file_exists( $alert_modal_path ) ) {
			require $alert_modal_path;
		}
	}
}