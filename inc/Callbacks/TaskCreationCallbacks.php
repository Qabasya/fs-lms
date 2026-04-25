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
 * Отвечает только за процесс создания поста задания:
 * получение типов, вставку поста, slug-генерацию и назначение мета-данных.
 *
 * @package Inc\Callbacks
 */
class TaskCreationCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	/**
	 * Конструктор.
	 *
	 * @param MetaBoxRepository     $metaboxes    Репозиторий привязок шаблонов и типов заданий
	 * @param BoilerplateRepository $boilerplates Репозиторий типовых условий (boilerplate)
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
	 * Обрабатывает пост-запрос из модального окна.
	 */
	public function ajaxCreateTask(): void {
		// 1. Авторизация
		$this->authorize( Nonce::TaskCreation );

		// 2. Сбор и базовая санитизация данных
		$subject_key     = $this->requireKey( 'subject_key', error: 'Не указан предмет. #TCC134' );
		$term_id         = $this->requireInt( 'term_id', error: 'Не выбран тип задания. #TCC134' );
		$title           = $this->sanitizeText( 'title' ) ?: 'Новое задание';
		$boilerplate_uid = $this->sanitizeText( 'boilerplate_uid' );

		try {
			// 3. Делегирование бизнес-логики оркестратору
			$new_id = $this->taskManager->createNewTask(
				$subject_key,
				$term_id,
				$title,
				$boilerplate_uid
			);

			// 4. Успешный ответ с ссылкой на редактирование
			$this->success(
				array(
					'redirect' => get_edit_post_link( $new_id, 'abs' ),
				)
			);

		} catch ( \Throwable $e ) {
			// 5. Обработка любых ошибок логики или БД
			// Трейт AjaxResponse залогирует ошибку автоматически
			$this->error( 'Не удалось создать задание: ' . $e->getMessage() );
		}
	}

	/**
	 * Возвращает типы заданий для выпадающего списка.
	 */
	public function ajaxGetTaskTypes(): void {
		$this->authorize( Nonce::TaskCreation );

		$subject_key = $this->requireKey( 'subject_key', 'GET', 'Предмет не указан' );

		$this->success(
			$this->metaboxes->getTaskTypes( $subject_key )
		);
	}

	/**
	 * Возвращает список доступных шаблонов (boilerplates) для типа задания.
	 */
	public function ajaxGetTaskBoilerplates(): void {
		$this->authorize( Nonce::TaskCreation );

		$subject_key = $this->requireKey( 'subject_key', 'GET' );
		$term_slug   = $this->requireKey( 'term_slug', 'GET' );

		$variants = $this->boilerplates->getBoilerplates( $subject_key, $term_slug );

		// Формируем легкий массив для фронтенда
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
