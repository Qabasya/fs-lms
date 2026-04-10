<?php

namespace Inc\Controllers;

use Inc\Callbacks\AdminCallbacks;
use Inc\Callbacks\SubjectSettingsCallbacks;
use Inc\Contracts\ServiceInterface;
use Inc\Controllers\Builders\SubjectsMenuBuilder;
use Inc\Core\BaseController;
use Inc\Registrars\MenuRegistrar;
use Inc\Registrars\SettingsRegistrar;

/**
 * Class AdminController
 *
 * Главный контроллер административной панели плагина.
 * Отвечает за страницы меню
 *
 * Реализует паттерн Orchestrator — координирует работу компонентов
 * для регистрации административного интерфейса:
 * - Билдеры (SubjectsMenuBuilder) — строят структуру меню
 * - Регистраторы (PluginRegistrar) — регистрируют меню в WordPress
 *
 * @package Inc\Controllers
 * @implements ServiceInterface
 */
class AdminController extends BaseController implements ServiceInterface {
	/**
	 * Конструктор.
	 *
	 * @param MenuRegistrar $menuRegistrar Регистратор меню
	 * @param SettingsRegistrar $settingsRegistrar Регистратор настроек ПОКА НЕ ИСПОЛЬЗУЕТСЯ
	 * @param AdminCallbacks $callbacks Коллбеки админ-панели
	 * @param SubjectsMenuBuilder $subjectsMenuBuilder Билдер меню предметов
	 */
	public function __construct(
		private MenuRegistrar $menuRegistrar,
//		private SettingsRegistrar $settingsRegistrar,
		private AdminCallbacks $callbacks,
		private SubjectsMenuBuilder $subjectsMenuBuilder
	) {
		parent::__construct();
	}

	/**
	 * Регистрирует все административные меню плагина.
	 *
	 * Основной метод, реализующий интерфейс ServiceInterface.
	 * Выполняет следующие шаги:
	 * 1. Собирает главные страницы меню
	 * 2. Собирает все подстраницы
	 * 3. Передаёт данные в регистратор и выполняет регистрацию
	 * 4. Удаляет автоматически созданные дублирующиеся пункты меню
	 *
	 * @return void
	 */
	public function register(): void {
		// Сбор конфигураций главных страниц
		$pages = $this->buildMainPages();

		// Сбор конфигураций всех подстраниц
		$subpages = $this->buildAllSubPages();

		// Передаём данные в регистратор и выполняем регистрацию
		$this->menuRegistrar->addPages( $pages )
		                    ->addSubPages( $subpages )
		                    ->register();

		// Удаляем дублирующиеся пункты меню, созданные WordPress автоматически
		$this->removeAutoSubMenuItems();
	}

// ============================ ФУНКЦИОНАЛ РЕПОЗИТОРИЯ И РЕГИСТРАТОРА ============================ //

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

// ====================== ПУНКТЫ ГЛАВНОГО МЕНЮ FS LMS ======================
	/**
	 * Собирает все подстраницы из разных источников.
	 *
	 * Формирует базовые подстраницы главного меню и объединяет их
	 * с подстраницами предметов из билдера.
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