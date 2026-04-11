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
 * - Рендеринг страниц админ-панели (adminDashboard)
 * - AJAX-обработку CRUD операций с предметами (store, update, delete)
 *
 * Если планируешь добавить новое действие, то:
 * 1. Зарегистрируй его работу в SubjectRepository
 * 2. Добавь хук AJAX
 * 3. Проверь алгоритм, добавь действие в проверку на существование предмета, проверь правила перезаписи
 * 4. Вызови зарегистрированный в репозитории метод через performRepositoryAction()
 * 5. Добавь сообщение пользователю в getSuccessMessage()
 * 6. Добавь AJAX обработчик
 *
 * @package Inc\Callbacks
 *
 * @method void render( string $template, array $data = [] ) — трейт TemplateRenderer
 */
class AdminCallbacks extends BaseController {
	use TemplateRenderer;

	/**
	 * Конструктор.
	 *
	 * Инициализирует репозиторий предметов и регистрирует AJAX-обработчики.
	 *
	 * @param SubjectRepository $subjects Репозиторий предметов
	 */
	public function __construct(
		private readonly SubjectRepository $subjects,
		private readonly TaskTypeRepository $taskTypes,
		private readonly BoilerplateController $boilerplateController
	) {
		parent::__construct();
	}


	/**
	 * Метод для пустой главной страницы (Dashboard)
	 */
	public function adminDashboard(): void {
		// Временная заглушка
		echo '<div class="wrap"><h1>Dashboard</h1><p>Данные о предметах</p></div>';
	}

	/**
	 * Страница настроек (там добавляем предметы и прочее)
	 */
	public function settingsPage(): void {
		$all_subjects = $this->subjects->readAll();
		$this->render( 'settings', [ 'subjects' => $all_subjects ] );
	}

	/**
	 * Метод-прослойка. Всю грязную работу теперь делает BoilerplateController.
	 */
	public function boilerplatePage(): void {
		$this->boilerplateController->displayPage();
	}


}