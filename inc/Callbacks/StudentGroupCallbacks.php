<?php

declare( strict_types=1 );

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\Enums\Nonce;
use Inc\Repositories\OptionsRepositories\StudentGroupMatrixRepository;
use Inc\Services\StudentGroupService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\AjaxResponse;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class StudentGroupCallbacks
 *
 * Обработчик AJAX-запросов для управления группами учеников.
 *
 * @package Inc\Callbacks
 *
 * ### Основные обязанности:
 *
 * 1. **Валидация безопасности** — проверка прав пользователя и верификация WordPress Nonce.
 * 2. **Входная санитизация** — безопасный сбор данных через трейт Sanitizer.
 * 3. **Оркестрация действий** — вызов бизнес-логики через StudentGroupService и отправка JSON ответов.
 */
class StudentGroupCallbacks extends BaseController {

	use Authorizer;
	use AjaxResponse;
	use Sanitizer;

	/**
	 * Конструктор обработчика.
	 *
	 * @param StudentGroupService $group_service Сервис управления группами
	 */
	public function __construct(
		private readonly StudentGroupService         $group_service,
		private readonly StudentGroupMatrixRepository $matrix_repository,
	) {
		parent::__construct();
	}

	/**
	 * AJAX-обработчик для создания новой группы.
	 * Экшен: fs_lms_save_student_group
	 *
	 * @return void
	 */
	public function ajaxSaveStudentGroup(): void {
		// Защита: проверяем права доступа
		$this->authorize( Nonce::Manager );

		// Безопасный сбор данных с помощью твоего трейта Sanitizer
		$title      = $this->requireText( 'title', error: 'Название группы обязательно для заполнения.' );
		$period_id  = $this->requireKey( 'period_id', error: 'Необходимо указать учебный период.' );
		$subject_id = $this->requireKey( 'subject_id', error: 'Необходимо указать предмет.' );
		$teacher_id = $this->requireInt( 'teacher_id', error: 'Необходимо выбрать преподавателя.' );

		// Вызываем бизнес-логику создания
		$group_dto = $this->group_service->createGroup( $title, $period_id, $subject_id, $teacher_id );

		// Унифицированный ответ через трейт AjaxResponse
		$this->respond(
			result: $group_dto ? array( 'group' => $group_dto->toArray() ) : false,
			error_msg: 'Не удалось создать группу. Возможно, группа с таким названием в этом периоде уже существует.',
			success_msg: 'Группа успешно создана.'
		);
	}

	/**
	 * AJAX-обработчик для удаления группы.
	 * Экшен: fs_lms_delete_student_group
	 *
	 * @return void
	 */
	public function ajaxGetStudentsByGroup(): void {
		$this->authorize( Nonce::Manager );

		$group_id  = $this->requireKey( 'group_id', error: 'ID группы не указан.' );
		$user_ids  = $this->matrix_repository->getStudentsByGroup( $group_id );

		$students = array();
		foreach ( $user_ids as $user_id ) {
			$user = get_userdata( $user_id );
			if ( $user ) {
				$students[] = array(
					'id'   => $user_id,
					'name' => $user->display_name,
				);
			}
		}

		$this->success( $students );
	}

	public function ajaxDeleteStudentGroup(): void {
		// Защита
		$this->authorize( Nonce::Manager );

		// Извлекаем уникальный ID (слаг) удаляемой группы
		$id = $this->requireKey( 'id', error: 'Идентификатор группы не указан.' );

		$deleted = $this->group_service->deleteGroup( $id );

		// Унифицированный ответ через трейт AjaxResponse
		$this->respond(
			result: $deleted ? array( 'id' => $id ) : false,
			error_msg: 'Ошибка удаления. Группа не найдена или уже удалена.',
			success_msg: 'Группа успешно удалена.'
		);
	}
}