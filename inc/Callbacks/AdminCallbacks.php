<?php

namespace Inc\Callbacks;

use Inc\Controllers\BoilerplateController;
use Inc\Core\BaseController;
use Inc\Repositories\SubjectRepository;
use Inc\Repositories\TaskTypeRepository;
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
class AdminCallbacks extends BaseController
{
	use TemplateRenderer;

	/**
	 * Конструктор.
	 *
	 * @param SubjectRepository       $subjects              Репозиторий предметов
	 * @param TaskTypeRepository      $taskTypes             Репозиторий типов заданий
	 * @param BoilerplateController   $boilerplateController Контроллер для страницы boilerplate
	 */
	public function __construct(
		private readonly SubjectRepository $subjects,
		private readonly TaskTypeRepository $taskTypes,
		private readonly BoilerplateController $boilerplateController
	) {
		parent::__construct();
	}

	/**
	 * Метод для главной страницы (Dashboard).
	 *
	 * @return void
	 */
	public function adminDashboard(): void
	{
		// Временная заглушка
		echo '<div class="wrap"><h1>Dashboard</h1><p>Данные о предметах</p></div>';
	}

	/**
	 * Страница настроек (добавление предметов и прочее).
	 *
	 * @return void
	 */
	public function settingsPage(): void
	{
		$all_subjects = $this->subjects->readAll();
		$this->render('settings', ['subjects' => $all_subjects]);
	}

	/**
	 * Метод-прослойка для страницы управления типовыми условиями (boilerplate).
	 * Вся логика отображения делегируется BoilerplateController.
	 *
	 * @return void
	 */
	public function boilerplatePage(): void
	{
		$this->boilerplateController->displayPage();
	}
}