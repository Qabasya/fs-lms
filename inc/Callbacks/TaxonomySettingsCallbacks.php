<?php

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\Repositories\TaxonomyRepository;


/**
 * Class TaxonomySettingsCallbacks
 *
 * Обработчики (коллбеки) для управления таксономиями через AJAX.
 *
 * Отвечает за:
 * - AJAX-обработку CRUD операций с таксономиями (store, update, delete)
 * - Защиту системных таксономий (например, task_number) от изменения/удаления
 * - Сброс правил перезаписи после операций с таксономиями
 *
 * @package Inc\Callbacks
 */
class TaxonomySettingsCallbacks extends BaseController {
	/**
	 * Репозиторий для работы с таксономиями.
	 *
	 * @var TaxonomyRepository
	 */
	protected TaxonomyRepository $taxonomies;

	/**
	 * Конструктор.
	 *
	 * Инициализирует репозиторий таксономий и регистрирует AJAX-обработчики.
	 *
	 * @param TaxonomyRepository $taxonomies Репозиторий таксономий
	 */

	public function __construct( TaxonomyRepository $taxonomies ) {
		parent::__construct();
		$this->taxonomies = $taxonomies;

		// Регистрация AJAX
		add_action( 'wp_ajax_fs_store_taxonomy', [ $this, 'storeTaxonomy' ] );
		add_action( 'wp_ajax_fs_update_taxonomy', [ $this, 'updateTaxonomy' ] );
		add_action( 'wp_ajax_fs_delete_taxonomy', [ $this, 'deleteTaxonomy' ] );
	}

// ====================== ОБЩАЯ ЛОГИКА ======================
	/**
	 * Общая функция для выполнения операций с таксономией.
	 *
	 * Реализует единый алгоритм для всех CRUD-операций:
	 * 1. Проверка nonce и прав доступа
	 * 2. Получение и валидация данных (subject_key, tax_slug, tax_name)
	 * 3. Защита системных таксономий (блокировка изменения/удаления)
	 * 4. Выполнение операции через репозиторий
	 * 5. Сброс правил перезаписи
	 * 6. Отправка JSON-ответа
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

		$subject_key = sanitize_title( $_POST['subject_key'] ?? '' );
		$tax_slug    = sanitize_title( $_POST['tax_slug'] ?? '' );
		$tax_name    = sanitize_text_field( $_POST['tax_name'] ?? '' );

		if ( in_array( $operation, ['store', 'update'], true ) && empty( $tax_name ) ) {
			wp_send_json_error( 'Название таксономии не может быть пустым' );
		}

		if ( empty( $subject_key ) || empty( $tax_slug ) ) {
			wp_send_json_error( 'Недостаточно данных для операции' );
		}

		// --- ЗАЩИТА СИСТЕМНОЙ ТАКСОНОМИИ ---
		if ( $tax_slug === "{$subject_key}_task_number" ) {
			wp_send_json_error( 'Эту таксономию нельзя изменять или удалять!' );
		}

		$success = false;
		$message = '';

		switch ( $operation ) {
			case 'store':
			case 'update':
				$success = $this->taxonomies->update([
					'subject_key' => $subject_key,
					'tax_slug'    => $tax_slug,
					'name'        => $tax_name
				]);
				$message = ($operation === 'store') ? "Таксономия создана" : "Таксономия обновлена";
				break;
			case 'delete':
				$success = $this->taxonomies->delete([
					'subject_key' => $subject_key,
					'tax_slug'    => $tax_slug
				]);
				$message = "Таксономия удалена";
				break;
		}

		if ( $success ) {
			flush_rewrite_rules();
			wp_send_json_success( $message );
		} else {
			wp_send_json_error( 'Ошибка репозитория при выполнении операции' );
		}
	}


// ====================== ТОНКИЕ AJAX-ОБРАБОТЧИКИ ======================
// Фасады для комплексных операций

	/**
	 * AJAX-обработчик создания новой таксономии.
	 *
	 * @return void
	 */
	public function storeTaxonomy(): void {
		$this->executeOperation( 'store' );
	}

	/**
	 * AJAX-обработчик обновления таксономии (Quick Edit).
	 *
	 * @return void
	 */
	public function updateTaxonomy(): void {
		$this->executeOperation( 'update' );
	}

	/**
	 * AJAX-обработчик удаления таксономии.
	 *
	 * @return void
	 */
	public function deleteTaxonomy(): void {
		$this->executeOperation( 'delete' );
	}
}