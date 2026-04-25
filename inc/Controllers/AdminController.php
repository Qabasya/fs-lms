<?php

namespace Inc\Controllers;

use Inc\Callbacks\AdminCallbacks;
use Inc\Callbacks\SubjectSettingsCallbacks;
use Inc\Contracts\ServiceInterface;
use Inc\Controllers\Builders\SubjectsMenuBuilder;
use Inc\Core\BaseController;
use Inc\Enums\Capability;
use Inc\Enums\MenuSlug;
use Inc\Enums\MenuTitle;
use Inc\Enums\PageTitle;
use Inc\Registrars\MenuRegistrar;
use Inc\Registrars\SettingsRegistrar;

/**
 * Class AdminController
 *
 * Главный контроллер административной панели плагина.
 *
 * @package Inc\Controllers
 * @implements ServiceInterface
 *
 * ### Основные обязанности класса:
 *
 * 1. **Регистрация административных меню** — создаёт иерархию пунктов меню
 *    в админ-панели WordPress (главное меню плагина, подменю, страницы предметов).
 *
 * 2. **Оркестрация компонентов регистрации** — координирует работу MenuRegistrar
 *    (непосредственная регистрация в WP) и SubjectsMenuBuilder (построение пунктов
 *    для каждого предмета).
 *
 * 3. **Управление дублирующимися пунктами меню** — удаляет автоматически созданные
 *    WordPress дубликаты для поддержания чистоты интерфейса.
 *
 * ### Архитектурная роль:
 *
 * Реализует паттерн Orchestrator (Оркестратор) — сам не занимается низкоуровневой
 * регистрацией, а делегирует специализированным компонентам:
 * - MenuRegistrar — регистрирует страницы в WordPress
 * - SubjectsMenuBuilder — строит структуру меню для предметов

 * ### Взаимодействие с другими компонентами:
 *
 * - **AdminCallbacks** — содержит методы-коллбеки для отрисовки страниц
 * - **SubjectsMenuBuilder** — строит конфигурации меню для каждого существующего предмета
 * - **MenuRegistrar** — выполняет фактическую регистрацию страниц в WordPress
 * - **Enums (Capability, MenuSlug, MenuTitle, PageTitle)** — централизованное хранение констант
 */
class AdminController extends BaseController implements ServiceInterface {

	/**
	 * Конструктор контроллера.
	 *
	 * Внедряет все необходимые зависимости через конструктор.
	 * Использует property promotion (PHP 8.4) для одновременного объявления
	 * свойств и их инициализации.
	 *
	 * @param MenuRegistrar       $menu_registrar        Регистратор меню.
	 *                                                    Отвечает за вызов функций add_menu_page() и add_submenu_page().
	 *
	 * @param AdminCallbacks      $callbacks             Объект с коллбеками для отрисовки страниц.
	 *                                                    Содержит методы adminDashboard(), settingsPage(), boilerplatePage().
	 *
	 * @param SubjectsMenuBuilder $subjects_menu_builder Билдер меню предметов.
	 *                                                    Строит конфигурации страниц для каждого предмета динамически.
	 */
	public function __construct(
		private readonly MenuRegistrar $menu_registrar,
		private readonly AdminCallbacks $callbacks,
		private readonly SubjectsMenuBuilder $subjects_menu_builder
	) {
		parent::__construct();
	}

	/**
	 * Регистрирует все административные меню плагина.
	 *
	 * Основной метод, реализующий интерфейс ServiceInterface.
	 * Вызывается при инициализации плагина для построения структуры админ-панели.
	 *
	 * Алгоритм работы:
	 * 1. Сбор конфигураций главных страниц меню
	 * 2. Сбор конфигураций всех подстраниц
	 * 3. Передача данных в MenuRegistrar и выполнение регистрации
	 * 4. Очистка дублирующихся пунктов меню
	 *
	 * @return void
	 */
	public function register(): void {
		// Сбор конфигураций главных страниц
		$pages = $this->buildMainPages();

		// Сбор конфигураций всех подстраниц
		$subpages = $this->buildAllSubPages();

		// Передаём данные в регистратор и выполняем регистрацию
		$this->menu_registrar->addPages( $pages )
							->addSubPages( $subpages )
							->register();

		// Удаляем дублирующиеся пункты меню, созданные WordPress автоматически
		$this->removeAutoSubMenuItems();
	}

	// ============================ ФУНКЦИОНАЛ РЕГИСТРАТОРА ============================ //

	/**
	 * Строит конфигурацию главных страниц меню.
	 *
	 * Формирует базовую конфигурацию главного меню плагина "FS LMS"
	 * и объединяет её со страницами предметов, возвращёнными билдером.
	 *
	 * Структура каждой страницы соответствует параметрам функции WordPress add_menu_page():
	 * - page_title — заголовок страницы (<title>)
	 * - menu_title — текст пункта меню
	 * - capability — минимальная роль/права доступа
	 * - menu_slug — уникальный идентификатор (используется в URL)
	 * - callback — функция/метод для отрисовки содержимого
	 * - icon_url — иконка пункта меню (Dashicons)
	 * - position — позиция в меню (чем меньше число, тем выше)
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
		// Главная страница плагина
		$pages = array(
			array(
				'page_title' => 'FS LMS Dashboard',
				'menu_title' => 'FS LMS',
				'capability' => Capability::ADMIN->value,
				'menu_slug'  => MenuSlug::MAIN->value,
				'callback'   => array( $this->callbacks, 'adminDashboard' ),
				'icon_url'   => 'dashicons-welcome-learn-more',
				'position'   => 4,
			),
		);

		// Добавляем страницы предметов из билдера
		return array_merge( $pages, $this->subjects_menu_builder->buildPages() );
	}

	/**
	 * Собирает все подстраницы из разных источников.
	 *
	 * Формирует три типа подстраниц:
	 * 1. Первая подстраница (дублирует главную — будет скрыта)
	 * 2. Страница настроек плагина
	 * 3. Скрытая страница управления типовыми условиями (не отображается в меню)
	 * 4. Подстраницы для каждого предмета (создаются билдером)
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
		$subpages = array();

		// Первая подстраница (дублирует главную, будет скрыта WordPress)
		// WordPress автоматически создаёт её, чтобы у родительского пункта был дочерний
		$subpages[] = array(
			'parent_slug' => MenuSlug::MAIN->value,
			'page_title'  => PageTitle::FIRST->value,
			'menu_title'  => MenuTitle::FIRST->value,
			'capability'  => Capability::ADMIN->value,
			'menu_slug'   => MenuSlug::MAIN->value,
			'callback'    => array( $this->callbacks, 'adminDashboard' ),
		);

		// Страница настроек плагина
		$subpages[] = array(
			'parent_slug' => MenuSlug::MAIN->value,
			'page_title'  => PageTitle::SECOND->value,
			'menu_title'  => MenuTitle::SECOND->value,
			'capability'  => Capability::ADMIN->value,
			'menu_slug'   => 'fs_lms_settings',
			'callback'    => array( $this->callbacks, 'settingsPage' ),
		);

		// Скрытая страница управления типовыми условиями (не отображается в боковом меню)
		// Parent_slug = '' делает страницу доступной только по прямому URL
		$subpages[] = array(
			'parent_slug' => '',
			'page_title'  => 'Управление типовыми условиями',
			'menu_title'  => 'Boilerplate Manager',
			'capability'  => Capability::ADMIN->value,
			'menu_slug'   => 'fs_boilerplate_manager',
			'callback'    => array( $this->callbacks, 'boilerplatePage' ),
		);

		// ===== НОВЫЕ СТРАНИЦЫ ДОБАВЛЯТЬ ЗДЕСЬ =====//

		// Добавляем подстраницы предметов (каждый предмет — отдельная подстраница)
		return array_merge( $subpages, $this->subjects_menu_builder->buildSubPages() );
	}

	// ====================== ВСПОМОГАТЕЛЬНЫЕ МЕТОДЫ ======================

	/**
	 * Удаляет автоматически созданные дублирующиеся пункты меню.
	 *
	 * Использует приоритет 999, чтобы гарантировать выполнение
	 * после создания всех пунктов меню (хуки с более низким приоритетом
	 * отработают раньше).
	 *
	 * @return void
	 */
	private function removeAutoSubMenuItems(): void {
		add_action(
			'admin_menu',
			function () {
				// Удаляем дублирующийся подпункт для страницы списка предметов
				remove_submenu_page(
					MenuSlug::SUBJECTS->value,
					MenuSlug::SUBJECTS->value
				);
			},
			999
		);
	}
}
