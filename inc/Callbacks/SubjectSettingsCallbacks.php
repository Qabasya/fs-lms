<?php

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\Repositories\MetaBoxRepository;
use Inc\Repositories\SubjectRepository;
use Inc\Services\TaxonomySeeder;

/**
 * Class SubjectSettingsCallbacks
 *
 * Обработчики (коллбеки) для управления предметами через AJAX.
 *
 * Отвечает за:
 * - AJAX-обработку CRUD операций с предметами (store, update, delete)
 * - Обновление шаблона для конкретного типа задания (update_term_template)
 * - Сидинг таксономий при создании предмета
 * - Сброс правил перезаписи при создании/удалении
 *
 * AJAX-хуки регистрируются в SubjectController.
 *
 * @package Inc\Callbacks
 */
class SubjectSettingsCallbacks extends BaseController {
	/**
	 * Репозиторий для работы с предметами.
	 *
	 * @var SubjectRepository
	 */
	private SubjectRepository $subjects;

	/**
	 * Репозиторий для работы с привязками заданий к шаблонам.
	 *
	 * @var MetaBoxRepository
	 */
	private MetaBoxRepository $metaboxes;

	/**
	 * Сервис для заполнения таксономий (сидинг номеров заданий).
	 *
	 * @var TaxonomySeeder
	 */
	private TaxonomySeeder $seeder;

	/**
	 * Конструктор.
	 *
	 * Инициализирует репозитории и сервис сидинга.
	 *
	 * @param SubjectRepository $subjects Репозиторий предметов
	 * @param TaxonomySeeder $seeder Сервис заполнения таксономий
	 * @param MetaBoxRepository $metaboxes Репозиторий привязок заданий к шаблонам
	 */
	public function __construct(
		SubjectRepository $subjects,
		TaxonomySeeder $seeder,
		MetaBoxRepository $metaboxes
	) {
		parent::__construct();
		$this->subjects  = $subjects;
		$this->seeder    = $seeder;
		$this->metaboxes = $metaboxes;
	}

	// ====================== ОБЩАЯ ЛОГИКА ======================

	/**
	 * Общая функция для выполнения операций с предметом.
	 *
	 * Реализует единый алгоритм для всех CRUD-операций:
	 * 1. Проверка nonce и прав доступа
	 * 2. Получение и валидация данных
	 * 3. Проверка существования предмета (для update/delete)
	 * 4. Выполнение операции через репозиторий
	 * 5. Сидинг таксономий (только для store)
	 * 6. Сброс правил перезаписи (для store/delete)
	 * 7. Отправка JSON-ответа
	 *
	 * @param string $operation Тип операции: 'store', 'update', 'delete', 'update_term_template'
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
				if ( ! $this->subjects->getByKey( $key ) ) {
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

				// 1. Валидация входных данных
				if ( ! $term_id || ! $template ) {
					wp_send_json_error( 'Недостаточно данных для обновления' );
				}

				// 2. Получаем объект термина, чтобы узнать его таксономию и slug
				$term = get_term( $term_id );
				if ( ! $term || is_wp_error( $term ) ) {
					wp_send_json_error( 'Тип задания не найден в базе WordPress' );
				}

				// 3. Вычисляем subject_key (например, из "phys_task_number" получаем "phys")
				// Это критически важно, так как репозиторий группирует данные по этому ключу
				$subject_key = str_replace( '_task_number', '', $term->taxonomy );

				// 4. Сохраняем в репозиторий.
				// В качестве task_number используем $term->slug (например, "1", "2"),
				// так как именно по нему MetaBoxController ищет шаблон в редакторе.
				$success = $this->metaboxes->updateAssignment(
					$subject_key,
					(string) $term->slug,
					$template
				);

				$message = "Шаблон для задания №{$term->slug} успешно сохранен!";
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