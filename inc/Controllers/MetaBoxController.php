<?php

namespace Inc\Controllers;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\MetaBoxes\Templates\StandardTaskTemplate;

/**
 * Контроллер управления метабоксами заданий.
 */
class MetaBoxController extends BaseController  implements ServiceInterface {
	/**
	 * Список доступных шаблонов.
	 * @var array
	 */
	private array $templates = [];

	/**
	 * Инициализация шаблонов.
	 */
	public function __construct() {
		 parent::__construct();

		// Регистрируем доступные шаблоны
		$this->templates['standard_task'] = new StandardTaskTemplate();
	}

	/**
	 * Точка входа в сервис (вызывается из Init.php!).
	 */
	public function register(): void {
		// Хук для вывода интерфейса метабоксов
		add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );

		// Хук для сохранения данных при обновлении поста
		add_action( 'save_post', [ $this, 'save_meta_boxes' ] );
	}

// ============================ ФУНКЦИОНАЛ РЕПОЗИТОРИЯ И РЕГИСТРАТОРА ============================ //

}