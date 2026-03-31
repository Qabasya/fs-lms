<?php

namespace Inc\Controllers;

use Inc\Callbacks\TaskCreationCallbacks;
use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;


/**
 * Контроллер для перехвата создания заданий и вывода модалки.
 * Пока бесполезен, но логику его регистрации некому передать
 * Его мы регистрируем в Init.php
 * Но работу всю выполняет TaskCreationCallbacks
 */

class TaskCreationController extends BaseController implements ServiceInterface {
	protected TaskCreationCallbacks $callbacks;

	public function __construct( TaskCreationCallbacks $callbacks ) {
		parent::__construct();
		$this->callbacks = $callbacks;
	}

	public function register(): void {

	}

}