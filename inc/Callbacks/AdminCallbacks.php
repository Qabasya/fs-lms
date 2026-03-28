<?php

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\Repositories\SubjectRepository;
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
	 * Репозиторий для работы с предметами.
	 *
	 * @var
	 */
	protected SubjectRepository $subjects;

	/**
	 * Конструктор.
	 *
	 * Инициализирует репозиторий предметов и регистрирует AJAX-обработчики.
	 *
	 * @param SubjectRepository $subjects Репозиторий предметов
	 */
	public function __construct(SubjectRepository $subjects) {
		parent::__construct();
		$this->subjects = $subjects;

		// Регистрация AJAX
		add_action( 'wp_ajax_fs_store_subject', [ $this, 'storeSubject' ] );
		add_action( 'wp_ajax_fs_update_subject', [ $this, 'updateSubject' ] );
		add_action( 'wp_ajax_fs_delete_subject', [ $this, 'deleteSubject' ] );
	}

// ====================== ОБЩАЯ ЛОГИКА ======================

	/**
	 * Общая функция для выполнения операций с предметом (создание, обновление, удаление)
	 *
	 * Это главный метод, который задаёт алгоритм действий:
	 * 1. Считать данные get_validated_subject_data()
	 * 2. Выполнить проверки
	 * 3. Выбрать действие (update/delete)
	 * 4. Сбросить или нет правила перезаписи flush_rewrite_rules()
	 * 5. Вывести сообщение пользователю
	 *
	 * Менять алгоритм ЗДЕСЬ
	 */
	protected function executeOperation( string $operation ): void {
		$data = $this->get_validated_subject_data();

		// Дополнительная проверка только при создании
		if ( $operation === 'store' && empty( $data['name'] ) ) {
			wp_send_json_error( 'Название обязательно для заполнения!' );
			return;
		}

		// Проверяем, существует ли предмет (для редактирования и удаления)
		if ( $operation === 'update' || $operation === 'delete' ) {
			if ( ! $this->subjects->get_by_key( $data['key'] ) ) {
				wp_send_json_error( 'Предмет не найден в базе!' );
				return;
			}
		}
		// Выполняем нужное действие
		$success = $this->performRepositoryAction( $operation, $data );

		if ( $success ) {
			// Сбрасываем правила перезаписи при создании и удалении
			if ( $operation === 'store' || $operation === 'delete' ) {
				flush_rewrite_rules();
			}

			$message = $this->getSuccessMessage( $operation, $data['name'] );
			wp_send_json_success( $message );
		} else {
			wp_send_json_error( "Не удалось выполнить операцию: {$operation}" );
		}
	}


	// ===== Действия в репозитории ===== //

	/**
	 * Выполняет действие в репозитории в зависимости от операции
	 */
	protected function performRepositoryAction( string $operation, array $data ): bool {
		if ( $operation === 'store' || $operation === 'update' ) {
			return $this->subjects->update( $data );
		}

		if ( $operation === 'delete' ) {
			return $this->subjects->delete( $data );
		}

		return false;
	}

	// ===== Сообщения ===== //

	/**
	 * Возвращает сообщение об успешном выполнении
	 * Выбор сообщения исходя из действия
	 */
	protected function getSuccessMessage( string $operation, string $name ): string {
		if ( $operation === 'store' ) {
			return sprintf( 'Предмет «%s» создан!', $name );
		}

		if ( $operation === 'update' ) {
			return sprintf( 'Предмет «%s» обновлен!', $name );
		}

		if ( $operation === 'delete' ) {
			return sprintf( 'Предмет «%s» удалён.', $name );
		}

		return 'Операция выполнена успешно';
	}

// ====================== ТОНКИЕ AJAX-ОБРАБОТЧИКИ ======================
// Фасады для комплексных операций

	/**
	 * Создание нового предмета
	 */
	public function storeSubject(): void {
		$this->executeOperation( 'store' );
	}

	/**
	 * Обновление существующего предмета
	 */
	public function updateSubject(): void {
		$this->executeOperation( 'update' );
	}

	/**
	 * Удаление предмета
	 */
	public function deleteSubject(): void {
		$this->executeOperation( 'delete' );
	}

	// Потом здесь добавляется exportSubject()//

// ====================== ВСПОМОГАТЕЛЬНЫЕ МЕТОДЫ ======================

	/**
	 * Получение и валидация данных из AJAX-запроса
	 */
	protected function get_validated_subject_data(): array {
		check_ajax_referer( 'fs_subject_nonce', 'security' );

		if ( ! current_user_can( self::ADMIN_CAPABILITY ) ) {
			wp_send_json_error( 'Нет прав' );
		}

		$name  = isset( $_POST['name'] ) ? sanitize_text_field( $_POST['name'] ) : '';
		$key   = isset( $_POST['key'] ) ? sanitize_title( $_POST['key'] ) : '';
		$count = isset( $_POST['tasks_count'] ) ? (int) $_POST['tasks_count'] : self::MIN_TASKS_COUNT;

		if ( empty( $key ) ) {
			wp_send_json_error( 'ID обязателен!' );
		}

		return [
			'name'        => $name,
			'key'         => $key,
			'tasks_count' => $count,
		];
	}

	/**
	 * Рендер страницы настроек (дашборд)
	 */
	public function adminDashboard(): void {
		$all_subjects = $this->subjects->read_all();
		$this->render( 'settings', [ 'subjects' => $all_subjects ] );
	}
}