<?php

declare( strict_types=1 );

namespace Inc\Controllers\Task;

use Inc\Core\BaseController;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Shared\Traits\TemplateRenderer;

/**
 * Class LegacyTaskImportPageController
 *
 * Отображение скрытой страницы разового переноса заданий со старой версии
 * сайта. Резолвится контейнером из AdminCallbacks — не сервис, register()
 * не требуется (аналогично BoilerplatePageController).
 *
 * @package Inc\Controllers\Task
 */
class LegacyTaskImportPageController extends BaseController {

	use TemplateRenderer;

	public function __construct(
		private readonly SubjectRepository $subjects,
	) {
		parent::__construct();
	}

	/** Главная точка входа (вызывается из AdminCallbacks::legacyTaskImportPage()). */
	public function displayPage(): void {
		$this->render(
			'admin/legacy-task-import',
			array(
				'subjects' => $this->subjects->readActive(),
			)
		);
	}
}
