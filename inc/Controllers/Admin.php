<?php

namespace Inc\Controllers;

use Inc\Callbacks\AdminCallbacks;
use Inc\Contracts\Service;
use Inc\Controllers\Builders\SubjectsMenuBuilder;
use Inc\Core\BaseController;
use Inc\Registrars\PluginRegistrar;

/**
 * Class Admin
 *
 * Главный контроллер административной панели плагина.
 *
 * Реализует паттерн Orchestrator — координирует работу компонентов
 * для регистрации административного интерфейса:
 * - Билдеры (SubjectsMenuBuilder) — строят структуру меню
 * - Регистраторы (PluginRegistrar) — регистрируют меню в WordPress
 * - Конфигураторы (SettingsConfigurator) — настраивают Settings API
 *
 * @package Inc\Controllers
 * @implements Service
 */
class Admin extends BaseController implements Service {
	/**
	 * Регистратор, объединяющий меню и настройки.
	 *
	 * @var PluginRegistrar
	 */
	private PluginRegistrar $registrar;

	/**
	 * Коллбеки для рендеринга страниц.
	 *
	 * @var AdminCallbacks
	 */
	private AdminCallbacks $callbacks;

	/**
	 * Билдер для построения меню предметов.
	 *
	 * @var SubjectsMenuBuilder
	 */
	private SubjectsMenuBuilder $subjectsMenuBuilder;

	/**
	 * Конфигуратор настроек плагина.
	 *
	 * @var SettingsConfigurator
	 */
	private SettingsConfigurator $settingsConfigurator;

	/**
	 * Конструктор.
	 *
	 * @param PluginRegistrar $registrar Композитный регистратор
	 * @param AdminCallbacks $callbacks Коллбеки админ-панели
	 * @param SubjectsMenuBuilder $subjectsMenuBuilder Билдер меню предметов
	 * @param SettingsConfigurator $settingsConfigurator Конфигуратор настроек
	 */
	public function __construct(
		PluginRegistrar $registrar,
		AdminCallbacks $callbacks,
		SubjectsMenuBuilder $subjectsMenuBuilder,
		SettingsConfigurator $settingsConfigurator
	) {
		parent::__construct();

		$this->registrar            = $registrar;
		$this->callbacks            = $callbacks;
		$this->subjectsMenuBuilder  = $subjectsMenuBuilder;
		$this->settingsConfigurator = $settingsConfigurator;
	}

	/**
	 * Регистрирует все административные меню и настройки плагина.
	 *
	 * Основной метод, реализующий интерфейс Service.
	 * Выполняет следующие шаги:
	 * 1. Конфигурирует настройки через SettingsConfigurator
	 * 2. Собирает главные страницы меню
	 * 3. Собирает все подстраницы
	 * 4. Передаёт данные в регистратор
	 * 5. Удаляет автоматически созданный дублирующийся пункт меню
	 *
	 * @return void
	 */
	public function register(): void {
		// Настройка Settings API
		$this->settingsConfigurator->configure( $this->registrar->settings() );

		// Построение структуры меню
		$pages    = $this->buildMainPages();
		$subpages = $this->buildAllSubPages();

		// Регистрация меню через регистратор
		$this->registrar->menu()
		                ->addPages( $pages )
		                ->addSubPages( $subpages );

		// Выполнение регистрации
		$this->registrar->register();

		// Удаляем дублирующийся пункт, который WordPress создаёт автоматически
		$this->removeAutoSubMenuItems();
	}

	/**
	 * Строит конфигурацию главных страниц меню.
	 *
	 * Объединяет главную страницу плагина с страницами,
	 * возвращёнными билдером предметов.
	 *
	 * @return array<int, array{
	 *     page_title: string,
	 *     menu_title: string,
	 *     capability: string,
	 *     menu_slug: string,
	 *     callback: array{0: AdminCallbacks, 1: string},
	 *     icon_url: string,
	 *     position: int
	 * }> Конфигурация главных страниц
	 */
	private function buildMainPages(): array {
		$pages = [
			[
				'page_title' => 'FS LMS Dashboard',
				'menu_title' => 'FS LMS',
				'capability' => self::ADMIN_CAPABILITY,
				'menu_slug'  => self::MAIN_MENU_SLUG,
				'callback'   => [ $this->callbacks, 'adminDashboard' ],
				'icon_url'   => 'dashicons-welcome-learn-more',
				'position'   => 4
			]
		];

		return array_merge( $pages, $this->subjectsMenuBuilder->buildPages() );
	}

	/**
	 * Собирает все подстраницы из разных источников.
	 *
	 * Объединяет подстраницы предметов из билдера
	 * и добавляет подстраницу настроек.
	 *
	 * @return array<int, array{
	 *     parent_slug: string,
	 *     page_title: string,
	 *     menu_title: string,
	 *     capability: string,
	 *     menu_slug: string,
	 *     callback: array{0: AdminCallbacks, 1: string}
	 * }> Конфигурация всех подстраниц
	 */

// ====================== ПУНКТЫ ГЛАВНОГО МЕНЮ FS LMS ======================
	private function buildAllSubPages(): array {
		$subpages   = [];
		$subpages[] = [
			'parent_slug' => self::MAIN_MENU_SLUG,
			'page_title'  => self::FIRST_PAGE_TITLE,
			'menu_title'  => self::FIRST_MENU_TITLE,
			'capability'  => self::ADMIN_CAPABILITY,
			'menu_slug'   => self::MAIN_MENU_SLUG,
			'callback'    => [ $this->callbacks, 'adminDashboard' ],
		];

		$subpages[] = [
			'parent_slug' => self::MAIN_MENU_SLUG,
			'page_title'  => self::SECOND_PAGE_TITLE,
			'menu_title'  => self::SECOND_MENU_TITLE,
			'capability'  => self::ADMIN_CAPABILITY,
			'menu_slug'   => 'fs_lms_settings',
			'callback'    => [ $this->callbacks, 'settingsPage' ],
		];

		// ===== НОВЫЕ СТРАНИЦЫ ДОБАВЛЯТЬ ЗДЕСЬ =====//

		return array_merge( $subpages, $this->subjectsMenuBuilder->buildSubPages() );
	}

// ====================== ВСПОМОГАТЕЛЬНЫЕ МЕТОДЫ ======================

	/**
	 * Удаляет автоматически созданный дублирующийся пункт меню.
	 *
	 * WordPress автоматически создаёт первый подпункт с тем же названием,
	 * что и родительский пункт. Этот метод удаляет дубликат для
	 * поддержания чистоты административного меню.
	 *
	 * Использует приоритет 999, чтобы гарантировать выполнение
	 * после создания всех пунктов меню.
	 *
	 * @return void
	 */
	private function removeAutoSubMenuItems(): void {
		add_action( 'admin_menu', function () {
			remove_submenu_page(
				BaseController::SUBJECTS_MENU_SLUG,
				BaseController::SUBJECTS_MENU_SLUG
			);
		}, 999 );
	}
}