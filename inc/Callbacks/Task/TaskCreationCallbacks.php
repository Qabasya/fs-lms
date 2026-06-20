<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Task;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Managers\TaskManager;
use Inc\Repositories\OptionsRepositories\BoilerplateRepository;
use Inc\Services\Task\TaskTypeService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class TaskCreationCallbacks
 *
 * AJAX-обработчики для создания новых заданий.
 *
 * @package Inc\Callbacks
 *
 * ### Основные обязанности:
 *
 * 1. **Создание задания** — получение данных из AJAX и делегирование TaskManager.
 * 2. **Получение типов заданий** — возврат списка доступных типов для выпадающего списка.
 * 3. **Получение шаблонов** — возврат списка boilerplate для конкретного типа задания.
 *
 * ### Архитектурная роль:
 *
 * Делегирует бизнес-логику TaskManager, получение типов заданий — TaskTypeService,
 * а получение boilerplate — BoilerplateRepository.
 */
class TaskCreationCallbacks extends BaseController {

	use Authorizer;  // Трейт с методами authorize(), requireKey(), requireInt(), success(), error()
	use Sanitizer;   // Трейт с методами sanitizeText() и др.

	/**
	 * Конструктор.
	 *
	 * @param TaskTypeService      $task_types    Сервис типов заданий
	 * @param BoilerplateRepository $boilerplates Репозиторий типовых условий
	 * @param TaskManager           $taskManager  Менеджер создания заданий
	 */
	public function __construct(
		private readonly TaskTypeService      $task_types,
		private readonly BoilerplateRepository $boilerplates,
		private readonly TaskManager          $taskManager,
	) {
		parent::__construct();
	}

	/**
	 * Создаёт новое задание через TaskManager.
	 *
	 * @return void
	 */
	public function ajaxCreateTask(): void {
		$this->authorize( Nonce::TaskCreation, Capability::ManageLMSAssignments );

		$subject_key     = $this->requireKey( 'subject_key', error: 'Не указан предмет. #TCC134' );
		$term_id         = $this->requireInt( 'term_id', error: 'Не выбран тип задания. #TCC134' );
		$title           = $this->sanitizeText( 'title' ) ?: 'Новое задание';
		$boilerplate_uid = $this->sanitizeText( 'boilerplate_uid' );

		$context = $this->sanitizeKey( 'context' );

		try {
			$new_id = $this->taskManager->createNewTask(
				$subject_key,
				$term_id,
				$title,
				$boilerplate_uid
			);

			if ( 'work' === $context ) {
				$this->success( array( 'id' => $new_id, 'title' => $title ) );
				return;
			}

			// get_edit_post_link() — возвращает URL для редактирования поста
			$this->success( array( 'redirect' => get_edit_post_link( $new_id, 'abs' ) ) );

		} catch ( \Throwable $e ) {
			$this->error( 'Не удалось создать задание: ' . $e->getMessage() );
		}
	}

	/**
	 * Возвращает типы заданий для выпадающего списка.
	 *
	 * @return void
	 */
	public function ajaxGetTaskTypes(): void {
		$this->authorize( Nonce::TaskCreation, Capability::ManageLMSAssignments );

		$subject_key = $this->requireKey( 'subject_key', 'GET', 'Предмет не указан' );

		$this->success( $this->task_types->getTaskTypes( $subject_key ) );
	}

	/**
	 * Возвращает список доступных шаблонов (boilerplate) для типа задания.
	 *
	 * @return void
	 */
	public function ajaxGetTaskBoilerplates(): void {
		$this->authorize( Nonce::TaskCreation, Capability::ManageLMSAssignments );

		$subject_key = $this->requireKey( 'subject_key', 'GET' );
		$term_slug   = $this->requireKey( 'term_slug', 'GET' );

		$variants = $this->boilerplates->getBoilerplates( $subject_key, $term_slug );

		// array_map() — преобразует массив DTO в упрощённый массив для фронтенда
		$response = array_map(
			static fn( $bp ) => array( 'uid' => $bp->uid, 'title' => $bp->title ),
			$variants
		);

		$this->success( $response );
	}
}