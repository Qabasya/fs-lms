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
		// Регистрация всех AJAX-обработчиков
		$this->registerAjaxActions();

	}

	/**
	 * Центральное место регистрации всех AJAX-действий.
	 *
	 * Все AJAX-обработчики добавляются здесь для удобства поддержки.
	 *
	 * @return void
	 */
	private function registerAjaxActions(): void {
		add_action( 'wp_ajax_fs_store_subject', [ $this, 'storeSubject' ] );
		add_action( 'wp_ajax_fs_update_subject', [ $this, 'updateSubject' ] );
		add_action( 'wp_ajax_fs_delete_subject', [ $this, 'deleteSubject' ] );
		add_action( 'wp_ajax_fs_update_term_template', [ $this, 'updateTaskTemplate' ] );
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
		// Проверка nonce для защиты от CSRF
		check_ajax_referer( 'fs_subject_nonce', 'security' );

		// Проверка прав доступа
		if ( ! current_user_can( self::ADMIN_CAPABILITY ) ) {
			wp_send_json_error( 'Нет прав' );
		}

		// Получение и санитизация общих данных
		$name  = isset( $_POST['name'] ) ? sanitize_text_field( $_POST['name'] ) : '';
		$key   = isset( $_POST['key'] ) ? sanitize_title( $_POST['key'] ) : '';
		$count = isset( $_POST['tasks_count'] ) ? (int) $_POST['tasks_count'] : 0;

		// Данные для операции update_term_template
		$term_id  = isset( $_POST['term_id'] ) ? (int) $_POST['term_id'] : 0;
		$template = isset( $_POST['template'] ) ? sanitize_text_field( $_POST['template'] ) : '';

		// Валидация для операций с предметами
		if ( in_array( $operation, [ 'store', 'update', 'delete' ] ) ) {
			// Проверка обязательного ID
			if ( empty( $key ) ) {
				wp_send_json_error( 'ID обязателен!' );
			}

			// Проверка названия для store и update
			if ( in_array( $operation, [ 'store', 'update' ] ) && empty( $name ) ) {
				wp_send_json_error( 'Название обязательно для заполнения!' );
			}

			// Проверка существования предмета для update и delete
			if ( in_array( $operation, [ 'update', 'delete' ] ) ) {
				if ( ! $this->subjects->get_by_key( $key ) ) {
					wp_send_json_error( 'Предмет не найден в базе!' );
				}
			}
		}

		$success = false;
		$message = '';

		// Выполнение операции в зависимости от типа
		switch ( $operation ) {
			case 'store':
				// Создание нового предмета
				$success = $this->subjects->update( [ 'key' => $key, 'name' => $name ] );
				if ( $success ) {
					// Сидинг номеров заданий для созданного предмета
					$this->seeder->seedTaskNumbers( "{$key}_task_number", $count, $key );
					flush_rewrite_rules(); // Обновляем правила перезаписи для новых CPT
				}
				$message = "Предмет «{$name}» успешно создан!";
				break;

			case 'update':
				// Обновление существующего предмета
				$success = $this->subjects->update( [ 'key' => $key, 'name' => $name ] );
				$message = "Предмет «{$name}» обновлен";
				break;

			case 'delete':
				// Удаление предмета
				$success = $this->subjects->delete( [ 'key' => $key ] );
				if ( $success ) {
					flush_rewrite_rules(); // Обновляем правила перезаписи после удаления
				}
				$message = "Предмет удалён";
				break;

			case 'update_term_template':
				// Обновление шаблона для конкретного типа задания (термина таксономии)
				if ( ! $term_id || ! $template ) {
					wp_send_json_error( 'Недостаточно данных для обновления' );
				}

				// Сохраняем предпочитаемый шаблон в мета-поле термина
				$success = (bool) update_term_meta( $term_id, '_fs_lms_preferred_template', $template );

				// Если значение не изменилось (update_term_meta вернул false),
				// но в базе уже есть нужное значение — считаем это успехом
				if ( ! $success && get_term_meta( $term_id, '_fs_lms_preferred_template', true ) === $template ) {
					$success = true;
				}
				$message = "Шаблон для типа задания сохранен!";
				break;
		}

		// Отправка ответа клиенту
		if ( $success ) {
			wp_send_json_success( $message );
		} else {
			wp_send_json_error( "Ошибка при выполнении операции: {$operation}" );
		}
	}


// ====================== ТОНКИЕ AJAX-ОБРАБОТЧИКИ ======================
// Фасады для комплексных операций

	/**
	 * AJAX-обработчик создания нового предмета.
	 *
	 * @return void
	 */
	public function storeSubject(): void {
		$this->executeOperation( 'store' );
	}

	/**
	 * AJAX-обработчик обновления существующего предмета.
	 *
	 * @return void
	 */
	public function updateSubject(): void {
		$this->executeOperation( 'update' );
	}

	/**
	 * AJAX-обработчик удаления предмета.
	 *
	 * @return void
	 */
	public function deleteSubject(): void {
		$this->executeOperation( 'delete' );
	}

	/**
	 * AJAX-обработчик обновления шаблона для конкретного типа задания.
	 *
	 * @return void
	 */
	public function updateTaskTemplate(): void {
		$this->executeOperation( 'update_term_template' );
	}
}