<?php

declare( strict_types=1 );

namespace Inc\Callbacks;

use Inc\Controllers\BoilerplatePageController;
use Inc\Core\BaseController;
use Inc\Repositories\AcademicPeriodRepository;
use Inc\Repositories\SubjectRepository;
use Inc\Shared\Traits\TemplateRenderer;

/**
 * Класс AdminCallbacks
 *
 * Обработчики (коллбеки) для административной панели WordPress.
 *
 * @package Inc\Callbacks
 *
 * ### Основные обязанности:
 *
 * 1. **Рендеринг Dashboard** — отображение главной страницы плагина (временная заглушка).
 * 2. **Рендеринг страницы настроек** — вывод интерфейса управления предметами.
 * 3. **Прокси для Boilerplate** — делегирование отображения страницы типовых условий.
 *
 * ### Архитектурная роль:
 *
 * Делегирует рендеринг страниц шаблонам, а бизнес-логику — контроллерам и репозиториям.
 *
 * @method void render(string $template, array $data = []) — метод трейта TemplateRenderer
 */
class AdminCallbacks extends BaseController {

	use TemplateRenderer;

	/**
	 * Конструктор.
	 *
	 * @param SubjectRepository         $subjects               Репозиторий предметов
	 * @param BoilerplatePageController $boilerplatePageController Контроллер страницы boilerplate
	 */
	public function __construct(
		private readonly SubjectRepository $subjects,
		private readonly AcademicPeriodRepository $periods,
		private readonly BoilerplatePageController $boilerplatePageController
	) {
		parent::__construct();
	}

	/**
	 * Главная страница Dashboard (временная заглушка).
	 *
	 * @return void
	 */
	public function adminDashboard(): void {
		// echo — выводит HTML-код непосредственно в браузер
		// Класс 'wrap' — стандартный контейнер WordPress для страниц админ-панели
		echo '<div class="wrap"><h1>Dashboard</h1><p>Данные о предметах</p></div>';
	}

	/**
	 * Страница настроек (управление предметами).
	 *
	 * @return void
	 */
	public function settingsPage(): void {
		$this->render(
			'admin/settings',
			array(
				'subjects'         => $this->subjects->readAll(),
				'academic_periods' => $this->periods->readAll(),
			)
		);
	}

	/**
	 * Страница групп
	 *
	 * @return void
	 */
	public function groupsPage(): void {
		$this->render(
			'admin/groups',
			array(
				'subjects'         => $this->subjects->readAll(),
				'academic_periods' => $this->periods->readAll(),
			)
		);
	}

	/**
	 * Прокси-метод для страницы управления типовыми условиями (boilerplate).
	 *
	 * @return void
	 */
	public function boilerplatePage(): void {
		// displayPage() — самостоятельно определяет режим (список/редактор) по параметрам PageRoutes
		$this->boilerplatePageController->displayPage();
	}
}
