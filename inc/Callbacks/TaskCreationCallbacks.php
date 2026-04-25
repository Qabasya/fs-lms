<?php

declare( strict_types=1 );

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\Enums\Nonce;
use Inc\Managers\TaskManager;
use Inc\Repositories\BoilerplateRepository;
use Inc\Repositories\MetaBoxRepository;
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
 * Делегирует бизнес-логику TaskManager, а получение данных — репозиториям.
 */
class TaskCreationCallbacks extends BaseController {
	
	use Authorizer;  // Трейт с методами authorize(), requireKey(), requireInt(), success(), error()
	use Sanitizer;   // Трейт с методами sanitizeText() и др.
	
	/**
	 * Конструктор.
	 *
	 * @param MetaBoxRepository     $metaboxes    Репозиторий привязок шаблонов и типов заданий
	 * @param BoilerplateRepository $boilerplates Репозиторий типовых условий
	 * @param TaskManager           $taskManager  Менеджер создания заданий
	 */
	public function __construct(
		private readonly MetaBoxRepository $metaboxes,
		private readonly BoilerplateRepository $boilerplates,
		private readonly TaskManager $taskManager,
	) {
		parent::__construct();
	}
	
	// ============================ AJAX-КОЛЛБЕКИ ============================ //
	
	/**
	 * Создаёт новое задание через TaskManager.
	 *
	 * @return void
	 */
	public function ajaxCreateTask(): void {
		$this->authorize( Nonce::TaskCreation );
		
		// requireInt() — требует наличие целочисленного значения в POST-данных
		$subject_key     = $this->requireKey( 'subject_key', error: 'Не указан предмет. #TCC134' );
		$term_id         = $this->requireInt( 'term_id', error: 'Не выбран тип задания. #TCC134' );
		$title           = $this->sanitizeText( 'title' ) ?: 'Новое задание';
		$boilerplate_uid = $this->sanitizeText( 'boilerplate_uid' );
		
		try {
			$new_id = $this->taskManager->createNewTask(
				$subject_key,
				$term_id,
				$title,
				$boilerplate_uid
			);
			
			// get_edit_post_link() — возвращает URL для редактирования поста
			// Параметр 'abs' — возвращает абсолютный URL (с http://)
			$this->success(
				array(
					'redirect' => get_edit_post_link( $new_id, 'abs' ),
				)
			);
			
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
		$this->authorize( Nonce::TaskCreation );
		
		// Второй параметр 'GET' — указывает на чтение данных из $_GET вместо $_POST
		$subject_key = $this->requireKey( 'subject_key', 'GET', 'Предмет не указан' );
		
		$this->success(
			$this->metaboxes->getTaskTypes( $subject_key )
		);
	}
	
	/**
	 * Возвращает список доступных шаблонов (boilerplates) для типа задания.
	 *
	 * @return void
	 */
	public function ajaxGetTaskBoilerplates(): void {
		$this->authorize( Nonce::TaskCreation );
		
		$subject_key = $this->requireKey( 'subject_key', 'GET' );
		$term_slug   = $this->requireKey( 'term_slug', 'GET' );
		
		$variants = $this->boilerplates->getBoilerplates( $subject_key, $term_slug );
		
		// array_map() — преобразует массив объектов в упрощённый массив для фронтенда
		// static fn() — статическое замыкание (экономит память, не привязывает $this)
		$response = array_map(
			static fn( $bp ) => array(
				'uid'   => $bp->uid,
				'title' => $bp->title,
			),
			$variants
		);
		
		$this->success( $response );
	}
}