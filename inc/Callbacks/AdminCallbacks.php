<?php

namespace Inc\Callbacks;

use Inc\Controllers\BoilerplatePageController;
use Inc\Core\BaseController;
use Inc\Repositories\SubjectRepository;
use Inc\Shared\Traits\TemplateRenderer;

/**
 * Class AdminCallbacks
 *
 * Обработчики (коллбеки) для административной панели WordPress.
 *
 * Отвечает за:
 * - Рендеринг страниц админ-панели (Dashboard, Настройки, Boilerplate)
 *
 * @package Inc\Callbacks
 *
 * @method void render(string $template, array $data = []) — трейт TemplateRenderer
 */
class AdminCallbacks extends BaseController {

	use TemplateRenderer;

	/**
	 * Конструктор.
	 *
	 * @param SubjectRepository     $subjects              Репозиторий предметов.
	 * @param BoilerplateController $boilerplateController Контроллер для страницы boilerplate.
	 */
	public function __construct(
		private readonly SubjectRepository $subjects,
		private readonly BoilerplatePageController $boilerplatePageController
	) {
		parent::__construct();
	}

	/**
	 * Метод для главной страницы (Dashboard).
	 *
	 * @return void
	 */
	public function adminDashboard(): void {
		// Временная заглушка, будет заменена на реальный дашборд.
		echo '<div class="wrap"><h1>Dashboard</h1><p>Данные о предметах</p></div>';
	}

	/**
	 * Страница настроек (добавление предметов и прочее).
	 *
	 * @return void
	 */
	public function settingsPage(): void {
		// Получение всех предметов из репозитория
		$all_subjects = $this->subjects->readAll();

		// Рендеринг шаблона настроек с переданными данными
		$this->render( 'settings', array( 'subjects' => $all_subjects ) );
	}

	/**
	 * Метод-прослойка для страницы управления типовыми условиями (boilerplate).
	 *
	 * Вся логика отображения (список, создание, редактирование)
	 * делегируется BoilerplateController.
	 *
	 * @return void
	 */
	public function boilerplatePage(): void {
		$this->boilerplatePageController->displayPage();
	}
}
