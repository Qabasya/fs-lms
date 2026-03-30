<?php

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\Repositories\SubjectRepository;
use Inc\Services\TaxonomySeeder;
use Inc\Shared\Traits\TemplateRenderer;


/**
 * Class SubjectSettingsCallbacks
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
class SubjectSettingsCallbacks extends BaseController {
	use TemplateRenderer;

	/**
	 * Репозиторий для работы с предметами.
	 *
	 * @var SubjectRepository
	 */
	protected SubjectRepository $subjects;

	/**
	 * Сервис для заполнения таксономий (сидинг номеров заданий).
	 *
	 * @var TaxonomySeeder
	 */
	protected TaxonomySeeder $seeder;

	/**
	 * Конструктор.
	 *
	 * Инициализирует репозиторий предметов, сервис сидинга
	 * и регистрирует AJAX-обработчики.
	 *
	 * @param SubjectRepository $subjects Репозиторий предметов
	 * @param TaxonomySeeder $seeder Сервис заполнения таксономий
	 */
	public function __construct( SubjectRepository $subjects, TaxonomySeeder $seeder ) {
		parent::__construct();
		$this->subjects = $subjects;
		$this->seeder   = $seeder;

		// Регистрация AJAX
		add_action( 'wp_ajax_fs_store_subject', [ $this, 'storeSubject' ] );
		add_action( 'wp_ajax_fs_update_subject', [ $this, 'updateSubject' ] );
		add_action( 'wp_ajax_fs_delete_subject', [ $this, 'deleteSubject' ] );
	}

// ====================== ОБЩАЯ ЛОГИКА ======================

	/**
	 * Общая функция для выполнения операций с предметом (создание, обновление, удаление)
	 * /**
	 *  Общая функция для выполнения операций с предметом.
	 *
	 *  Реализует единый алгоритм для всех CRUD-операций:
	 *  1. Проверка nonce и прав доступа
	 *  2. Получение и валидация данных
	 *  3. Проверка существования предмета (для update/delete)
	 *  4. Выполнение операции через репозиторий
	 *  5. Сидинг таксономий (только для store)
	 *  6. Сброс правил перезаписи (для store/delete)
	 *  7. Отправка JSON-ответа
	 *
	 * @param string $operation Тип операции: 'store', 'update', 'delete'
	 *
	 * @return void Отправляет JSON-ответ через wp_send_json_*()
	 */
	protected function executeOperation( string $operation ): void {
		check_ajax_referer( 'fs_subject_nonce', 'security' );

		if ( ! current_user_can( self::ADMIN_CAPABILITY ) ) {
			wp_send_json_error( 'Нет прав' );
		}

		$name  = isset( $_POST['name'] ) ? sanitize_text_field( $_POST['name'] ) : '';
		$key   = isset( $_POST['key'] ) ? sanitize_title( $_POST['key'] ) : '';
		$count = isset( $_POST['tasks_count'] ) ? (int) $_POST['tasks_count'] : 0;

		if ( empty( $key ) ) {
			wp_send_json_error( 'ID обязателен!' );
		}

		if ( in_array( $operation, [ 'store', 'update' ], true ) && empty( $name ) ) {
			wp_send_json_error( 'Название обязательно для заполнения!' );

			return;
		}

		// Проверка существования для редактирования и удаления
		if ( in_array( $operation, [ 'update', 'delete' ], true ) ) {
			if ( ! $this->subjects->get_by_key( $key ) ) {
				wp_send_json_error( 'Предмет не найден в базе!' );

				return;
			}
		}

		$success = false;
		$message = '';

		switch ( $operation ) {
			case 'store':
				$success = $this->subjects->update( [ 'key' => $key, 'name' => $name ] );
				if ( $success ) {
					// Разовый сидинг номеров заданий
					$this->seeder->seedTaskNumbers( "{$key}_task_number", $count );
					flush_rewrite_rules();
				}
				$message = "Предмет «{$name}» успешно создан!";
				break;

			case 'update':
				$success = $this->subjects->update( [ 'key' => $key, 'name' => $name ] );
				$message = "Предмет «{$name}» обновлен";
				break;

			case 'delete':
				$success = $this->subjects->delete( [ 'key' => $key ] );
				if ( $success ) {
					flush_rewrite_rules();
				}
				$message = "Предмет удалён";
				break;
		}

		if ( $success ) {
			wp_send_json_success( $message );
		} else {
			wp_send_json_error( "Ошибка при выполнении операции: {$operation}" );
		}
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
}